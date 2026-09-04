<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Support\TeamBalancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TeamBalancer::suggest() arma 2 equipos a partir de la lista de conectados
 * de Cod2RconClient::status() (slot/score/ping/guid/name/ip) y el rango
 * calculado por PlayerRankCalculator -- probado aca contra colecciones de
 * rango armadas a mano, sin tocar la base de datos, ya que la funcion no
 * depende de Eloquent para nada.
 */
class TeamBalancerTest extends TestCase
{
    use RefreshDatabase;

    private function rank(int $guid, float $score, string $rango): object
    {
        return (object) ['guid' => $guid, 'score' => $score, 'rango' => $rango];
    }

    /**
     * 9 partidas completas con $kills bajas/partida (1 muerte/partida) --
     * mismo patron que PlayerRankFormulaTest::makePlayerWithKd(), inline
     * porque solo hace falta para el test de transicion de mas abajo.
     */
    private function makeSeasonedPlayer(Server $server, int $seasonId, int $kills, Player $teammate): Player
    {
        $player = Player::create(['guid' => random_int(100000, 999999), 'last_name' => 'P', 'last_name_plain' => 'P']);

        for ($m = 0; $m < 9; $m++) {
            $filler = Player::create(['guid' => random_int(1000000, 9999999), 'last_name' => 'F', 'last_name_plain' => 'F']);
            $match = GameMatch::create([
                'server_id' => $server->id, 'season_id' => $seasonId,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);

            $rounds = [];
            for ($i = 1; $i <= 13; $i++) {
                $rounds[] = Round::create([
                    'server_id' => $server->id, 'match_id' => $match->id,
                    'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
                    'winner_guids' => [$player->guid, $teammate->guid],
                ]);
            }

            for ($k = 0; $k < $kills; $k++) {
                Kill::create([
                    'round_id' => $rounds[$k]->id, 'match_id' => $match->id,
                    'attacker_player_id' => $player->id, 'attacker_guid' => $player->guid, 'attacker_name' => $player->last_name, 'attacker_team' => 'allies',
                    'victim_player_id' => $filler->id, 'victim_guid' => $filler->guid, 'victim_name' => $filler->last_name, 'victim_team' => 'axis',
                    'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                    'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
                ]);
            }
            Kill::create([
                'round_id' => $rounds[$kills]->id, 'match_id' => $match->id,
                'attacker_player_id' => $filler->id, 'attacker_guid' => $filler->guid, 'attacker_name' => $filler->last_name, 'attacker_team' => 'axis',
                'victim_player_id' => $player->id, 'victim_guid' => $player->guid, 'victim_name' => $player->last_name, 'victim_team' => 'allies',
                'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
            ]);
        }

        return $player;
    }

    public function test_fewer_than_min_players_returns_not_enough(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'a'],
            ['guid' => 2, 'name' => 'b'],
            ['guid' => 3, 'name' => 'c'],
        ];

        $result = TeamBalancer::suggest($players, collect());

        $this->assertFalse($result->enough);
        $this->assertSame(3, $result->eligible);
        $this->assertCount(0, $result->teamA);
        $this->assertCount(0, $result->teamB);
    }

    public function test_bots_are_excluded_and_counted_separately(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'p1'],
            ['guid' => 2, 'name' => 'p2'],
            ['guid' => 3, 'name' => 'p3'],
            ['guid' => 4, 'name' => 'p4'],
            ['guid' => 0, 'name' => 'bot1'],
            ['guid' => 0, 'name' => 'bot2'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 90, 'A'),
            2 => $this->rank(2, 70, 'B'),
            3 => $this->rank(3, 40, 'D'),
            4 => $this->rank(4, 10, 'E'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);

        $this->assertTrue($result->enough);
        $this->assertSame(2, $result->bots);
        $this->assertSame(4, $result->eligible);
    }

    public function test_snake_draft_balances_total_score_and_keeps_min_two_per_team(): void
    {
        // Scores 100, 80, 60, 40 -- snake draft (A,B,B,A) should give
        // A = {100, 40} = 140 and B = {80, 60} = 140, a perfect split.
        $players = [
            ['guid' => 1, 'name' => 'best'],
            ['guid' => 2, 'name' => 'second'],
            ['guid' => 3, 'name' => 'third'],
            ['guid' => 4, 'name' => 'worst'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 100, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 60, 'C'),
            4 => $this->rank(4, 40, 'D'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);

        $this->assertTrue($result->enough);
        $this->assertCount(2, $result->teamA);
        $this->assertCount(2, $result->teamB);
        $this->assertSame(140.0, $result->scoreA);
        $this->assertSame(140.0, $result->scoreB);
        $this->assertSame(['best', 'worst'], $result->teamA->pluck('name')->all());
        $this->assertSame(['second', 'third'], $result->teamB->pluck('name')->all());
    }

    public function test_unranked_connected_player_gets_default_neutral_score(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'ranked1'],
            ['guid' => 2, 'name' => 'ranked2'],
            ['guid' => 3, 'name' => 'ranked3'],
            ['guid' => 99, 'name' => 'brand-new-player'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 90, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 20, 'E'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);

        $newPlayer = $result->teamA->concat($result->teamB)->firstWhere('guid', 99);

        $this->assertNotNull($newPlayer);
        $this->assertNull($newPlayer->rango);
        $this->assertSame(TeamBalancer::DEFAULT_SCORE, $newPlayer->score);
    }

    public function test_unranked_connected_player_uses_the_seed_score_when_a_server_is_given(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $oldSeason = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);

        // Dos jugadores calificados en T1 (n=2, minimo para que
        // calculateForServer() pueda calcular percentiles) -- el veterano
        // con K/D alto, otro con K/D bajo de relleno.
        $veteran = $this->makeSeasonedPlayer($server, $oldSeason->id, 5, $teammate);
        $this->makeSeasonedPlayer($server, $oldSeason->id, 1, $teammate);

        $oldSeason->update(['ended_at' => now()]);
        Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $players = [
            ['guid' => 1, 'name' => 'ranked1'],
            ['guid' => 2, 'name' => 'ranked2'],
            ['guid' => 3, 'name' => 'ranked3'],
            ['guid' => $veteran->guid, 'name' => 'veteran'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 90, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 20, 'E'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks, $server);

        $veteranRow = $result->teamA->concat($result->teamB)->firstWhere('guid', $veteran->guid);

        // Sin partidas todavia en T2, transitionScoresForServer() debe
        // devolver exactamente su semilla (100.0 -- el mejor K/D de los 2
        // calificados en T1), no el DEFAULT_SCORE=50 plano.
        $this->assertNotSame(TeamBalancer::DEFAULT_SCORE, $veteranRow->score);
        $this->assertSame(100.0, $veteranRow->score);
    }

    // -- "Mantener asignaciones anteriores" (2026-09-04) -----------------

    public function test_previous_assignments_keeps_already_assigned_players_and_only_places_new_ones(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'a'],
            ['guid' => 2, 'name' => 'b'],
            ['guid' => 3, 'name' => 'c'],
            ['guid' => 4, 'name' => 'new-player'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 100, 'A'),
            2 => $this->rank(2, 10, 'D'),
            3 => $this->rank(3, 50, 'B'),
            4 => $this->rank(4, 30, 'C'),
        ]);

        // 1 y 3 ya estaban en A (total 150), 2 ya estaba en B (total 10) --
        // el 4 es nuevo, nunca vio una sugerencia antes.
        $previous = [1 => 'A', 2 => 'B', 3 => 'A'];

        $result = TeamBalancer::suggest($players, $ranks, null, $previous);

        $this->assertTrue($result->enough);
        // Los ya asignados no se movieron -- 1 y 3 siguen en A, 2 sigue en B.
        $this->assertSame([1, 3], $result->teamA->pluck('guid')->sort()->values()->all());
        // El nuevo (guid 4) fue al equipo con el total mas bajo en ese
        // momento (B=10 contra A=150), no a un snake draft desde cero.
        $this->assertSame([2, 4], $result->teamB->pluck('guid')->sort()->values()->all());
    }

    public function test_previous_assignments_ignores_entries_for_players_no_longer_connected(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'a'],
            ['guid' => 2, 'name' => 'b'],
            ['guid' => 3, 'name' => 'c'],
            ['guid' => 4, 'name' => 'd'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 100, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 60, 'C'),
            4 => $this->rank(4, 40, 'D'),
        ]);

        // guid 999 ya no esta conectado -- no debe romper nada, se ignora.
        $previous = [1 => 'A', 999 => 'B'];

        $result = TeamBalancer::suggest($players, $ranks, null, $previous);

        $this->assertTrue($result->enough);
        $this->assertSame(4, $result->teamA->count() + $result->teamB->count());
        $this->assertTrue($result->teamA->contains('guid', 1));
    }

    public function test_previous_assignments_still_requires_the_minimum_player_count(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'a'],
            ['guid' => 2, 'name' => 'b'],
        ];

        $result = TeamBalancer::suggest($players, collect(), null, [1 => 'A']);

        $this->assertFalse($result->enough);
    }

    public function test_remember_and_previous_assignments_roundtrip(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server-cache', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        Cache::forget("team-balance:last-assignments:{$server->id}");

        $this->assertNull(TeamBalancer::previousAssignments($server));

        $players = [
            ['guid' => 1, 'name' => 'a'],
            ['guid' => 2, 'name' => 'b'],
            ['guid' => 3, 'name' => 'c'],
            ['guid' => 4, 'name' => 'd'],
        ];
        $ranks = collect([
            1 => $this->rank(1, 100, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 60, 'C'),
            4 => $this->rank(4, 40, 'D'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);
        TeamBalancer::rememberAssignments($server, $result);

        $stored = TeamBalancer::previousAssignments($server);

        $this->assertNotNull($stored);
        // Snake draft con estos 4 scores: A,B,B,A -- 1 y 4 en A, 2 y 3 en B.
        $this->assertSame('A', $stored[1]);
        $this->assertSame('B', $stored[2]);
        $this->assertSame('B', $stored[3]);
        $this->assertSame('A', $stored[4]);
    }

    public function test_remember_assignments_does_nothing_when_not_enough_players(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server-cache-2', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        Cache::put("team-balance:last-assignments:{$server->id}", [1 => 'A'], now()->addHour());

        $notEnough = (object) ['enough' => false, 'eligible' => 2, 'bots' => 0, 'teamA' => collect(), 'teamB' => collect()];
        TeamBalancer::rememberAssignments($server, $notEnough);

        $this->assertSame([1 => 'A'], TeamBalancer::previousAssignments($server));
    }
}
