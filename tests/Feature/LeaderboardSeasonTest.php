<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);
    }

    /** Partida real que llegó a 13 rondas (cuenta) — crea 1 kill de $attacker contra $victim. */
    private function realMatch(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => $map,
                'gametype' => 'sd',
                'started_at' => now(),
                'ended_at' => now(),
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'hitloc' => 'head',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    /** Partida abandonada: solo 2 rondas, sin MatchEnd -- no debe contar. */
    private function abandonedMatch(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $round = Round::create([
            'server_id' => $this->server->id,
            'match_id' => $match->id,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'hitloc' => 'head',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_ranking_without_season_param_shows_only_the_active_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->kills); // solo las 2 de Temporada 2 (la activa), no la 1 de Temporada 1
    }

    public function test_ranking_with_season_all_shows_every_season_combined(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'season' => 'all']));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2, $row->kills); // las 2 partidas, de las 2 temporadas
    }

    public function test_ranking_excludes_abandoned_matches_in_any_season(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($season->id, $attacker, $victim);
        $this->abandonedMatch($season->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(1, $row->kills); // solo la real, la abandonada no suma
    }

    public function test_ranking_for_a_specific_closed_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'season' => $oldSeason->id]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2, $row->kills); // las 2 de Temporada 1, no la de Temporada 2
    }
}
