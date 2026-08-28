<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido de un jugador (2026-08-28): "un grid por ronda donde se vea quien
 * mato a quien y con que arma, o si hubo un clutch" -- para revisar jugadas
 * raras o sacar clips. Reusa la misma definicion de clutch que
 * SpecialtyController::clutches() (roster completo de winner_guids menos
 * quien murio ESA ronda, un solo sobreviviente en un roster de 3+), aplicada
 * por ronda dentro de una sola partida.
 */
class MatchRoundDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function makeKill(Round $round, GameMatch $match, Player $attacker, Player $victim, string $weapon = 'kar98k_mp'): Kill
    {
        return $this->makeKillWithSides($round, $match, $attacker, 'allies', $victim, 'axis', $weapon);
    }

    private function makeKillWithSides(Round $round, GameMatch $match, Player $attacker, string $attackerTeam, Player $victim, string $victimTeam, string $weapon = 'kar98k_mp'): Kill
    {
        return Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => $attackerTeam,
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => $victimTeam,
            'weapon' => $weapon, 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false,
            'occurred_at' => now(),
        ]);
    }

    public function test_round_grid_shows_kills_grouped_by_round_and_detects_a_clutch(): void
    {
        $match = GameMatch::create(['server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        $survivor = Player::create(['guid' => 1, 'last_name' => 'Survivor', 'last_name_plain' => 'Survivor']);
        $mate1 = Player::create(['guid' => 2, 'last_name' => 'Mate1', 'last_name_plain' => 'Mate1']);
        $mate2 = Player::create(['guid' => 3, 'last_name' => 'Mate2', 'last_name_plain' => 'Mate2']);
        $enemy = Player::create(['guid' => 4, 'last_name' => 'Enemy', 'last_name_plain' => 'Enemy']);

        // Roster of 3 (allies), 2 die during the round, 1 survives and gets the
        // round-winning kill -- a real clutch.
        $round = Round::create([
            'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [1, 2, 3],
        ]);

        $this->makeKill($round, $match, $enemy, $mate1);
        $this->makeKill($round, $match, $enemy, $mate2);
        $this->makeKill($round, $match, $survivor, $enemy, 'frag_grenade_mp');

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();

        $roundDetails = $response->viewData('roundDetails');
        $this->assertCount(1, $roundDetails);

        $rd = $roundDetails->first();
        $this->assertSame(3, $rd->kills->count());
        $this->assertSame(1, $rd->clutchGuid); // survivor's guid

        $response->assertSee('Ronda 1', false);
        $response->assertSee('Survivor', false);
        $response->assertSee('Enemy', false);
    }

    public function test_a_round_with_no_survivors_pattern_is_not_flagged_as_a_clutch(): void
    {
        $match = GameMatch::create(['server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);
        $c = Player::create(['guid' => 3, 'last_name' => 'C', 'last_name_plain' => 'C']);
        $enemy = Player::create(['guid' => 4, 'last_name' => 'Enemy', 'last_name_plain' => 'Enemy']);

        // Roster of 3, only 1 dies -- 2 survivors, not a clutch (needs exactly 1).
        $round = Round::create([
            'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [1, 2, 3],
        ]);

        $this->makeKill($round, $match, $enemy, $c);
        $this->makeKill($round, $match, $a, $enemy);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();

        $rd = $response->viewData('roundDetails')->first();
        $this->assertNull($rd->clutchGuid);
    }

    public function test_rounds_with_no_kills_still_appear_in_the_grid(): void
    {
        $match = GameMatch::create(['server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
        Round::create(['server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        $response = $this->get(route('matches.show', $match));
        $response->assertOk();

        $rd = $response->viewData('roundDetails')->first();
        $this->assertSame(0, $rd->kills->count());
        $this->assertNull($rd->clutchGuid);
    }

    /**
     * Linea de tiempo (pedido de un jugador, 2026-08-28): verde/gris por ronda
     * segun quien ganó ESA ronda especifica -- no el lado "actual" del jugador en
     * todo el match (los lados cambian en el entretiempo), sino el lado real
     * dentro de esa ronda puntual.
     */
    public function test_round_winning_side_is_axis_when_the_winning_roster_played_axis_that_round(): void
    {
        $match = GameMatch::create(['server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        $winner = Player::create(['guid' => 1, 'last_name' => 'Winner', 'last_name_plain' => 'Winner']);
        $loser = Player::create(['guid' => 2, 'last_name' => 'Loser', 'last_name_plain' => 'Loser']);

        $round = Round::create([
            'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [1],
        ]);

        $this->makeKillWithSides($round, $match, $winner, 'axis', $loser, 'allies');

        $response = $this->get(route('matches.show', $match));
        $rd = $response->viewData('roundDetails')->first();

        $this->assertSame('axis', $rd->winningSide);
    }

    public function test_round_winning_side_is_allies_when_the_winning_roster_played_allies_that_round(): void
    {
        $match = GameMatch::create(['server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        $winner = Player::create(['guid' => 1, 'last_name' => 'Winner', 'last_name_plain' => 'Winner']);
        $loser = Player::create(['guid' => 2, 'last_name' => 'Loser', 'last_name_plain' => 'Loser']);

        $round = Round::create([
            'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [1],
        ]);

        // Same winning guid as the test above, but this time THEY played allies in
        // this specific round -- proves it's not just "guid 1 always = axis".
        $this->makeKillWithSides($round, $match, $winner, 'allies', $loser, 'axis');

        $response = $this->get(route('matches.show', $match));
        $rd = $response->viewData('roundDetails')->first();

        $this->assertSame('allies', $rd->winningSide);
    }

    public function test_round_winning_side_is_null_when_there_is_no_side_signal(): void
    {
        $match = GameMatch::create(['server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
        Round::create([
            'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [1],
        ]);

        $response = $this->get(route('matches.show', $match));
        $rd = $response->viewData('roundDetails')->first();

        $this->assertNull($rd->winningSide, 'No kills at all in the round -- no side signal, must not guess.');
    }
}
