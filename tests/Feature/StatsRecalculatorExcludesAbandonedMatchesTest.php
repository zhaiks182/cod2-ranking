<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use App\Support\StatsRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seguimiento del filtro de listado (GameMatchReachedConclusionTest): el
 * dueño confirmó que los kills de una partida abandonada sin resultado real
 * tampoco deben sumar al ranking, no solo desaparecer del listado.
 */
class StatsRecalculatorExcludesAbandonedMatchesTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        return Server::create([
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

    private function makeMatch(Server $server, bool $ended): GameMatch
    {
        return GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => $ended ? now() : null,
        ]);
    }

    private function addKill(Server $server, GameMatch $match, Player $attacker, Player $victim): void
    {
        $round = Round::create([
            'server_id' => $server->id,
            'match_id' => $match->id,
            'map' => $match->map,
            'gametype' => $match->gametype,
            'started_at' => now(),
        ]);

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => (int) $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'victim_player_id' => $victim->id,
            'victim_guid' => (int) $victim->guid,
            'victim_name' => $victim->last_name,
            'weapon' => 'weapon_mp5',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'occurred_at' => now(),
        ]);
    }

    public function test_kills_from_an_abandoned_match_are_excluded_from_totals(): void
    {
        $server = $this->makeServer();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);

        $abandoned = $this->makeMatch($server, ended: true);
        $this->addKill($server, $abandoned, $attacker, $victim);

        StatsRecalculator::recalculateAll();

        $attacker->refresh();
        $this->assertSame(0, $attacker->kills_total, 'A kill from an abandoned match must not count toward kills_total.');

        $mapStat = \App\Models\PlayerMapStat::where('player_id', $attacker->id)->first();
        $this->assertNull($mapStat, 'An abandoned match must not create/update player_map_stats.');

        $serverStat = \App\Models\PlayerServerStat::where('player_id', $attacker->id)->first();
        $this->assertNull($serverStat, 'An abandoned match must not create/update player_server_stats.');
    }

    public function test_kills_from_a_still_live_match_still_count(): void
    {
        $server = $this->makeServer();
        $attacker = Player::create(['guid' => 333, 'last_name' => 'Attacker2', 'last_name_plain' => 'Attacker2']);
        $victim = Player::create(['guid' => 444, 'last_name' => 'Victim2', 'last_name_plain' => 'Victim2']);

        $live = $this->makeMatch($server, ended: false);
        $this->addKill($server, $live, $attacker, $victim);

        StatsRecalculator::recalculateAll();

        $attacker->refresh();
        $this->assertSame(1, $attacker->kills_total, 'A kill from a still-live match must count immediately.');
    }

    public function test_kills_from_a_match_that_reached_13_rounds_still_count(): void
    {
        $server = $this->makeServer();
        $attacker = Player::create(['guid' => 555, 'last_name' => 'Attacker3', 'last_name_plain' => 'Attacker3']);
        $victim = Player::create(['guid' => 666, 'last_name' => 'Victim3', 'last_name_plain' => 'Victim3']);

        $finished = $this->makeMatch($server, ended: true);
        for ($i = 0; $i < 13; $i++) {
            Round::create([
                'server_id' => $server->id,
                'match_id' => $finished->id,
                'map' => $finished->map,
                'gametype' => $finished->gametype,
                'started_at' => now(),
                'winner_guids' => ['ABC'],
            ]);
        }
        $this->addKill($server, $finished, $attacker, $victim);

        StatsRecalculator::recalculateAll();

        $attacker->refresh();
        $this->assertSame(1, $attacker->kills_total);
    }

    public function test_kills_from_a_match_with_match_end_event_still_count(): void
    {
        $server = $this->makeServer();
        $attacker = Player::create(['guid' => 777, 'last_name' => 'Attacker4', 'last_name_plain' => 'Attacker4']);
        $victim = Player::create(['guid' => 888, 'last_name' => 'Victim4', 'last_name_plain' => 'Victim4']);

        $finished = $this->makeMatch($server, ended: true);
        $this->addKill($server, $finished, $attacker, $victim);

        MatchEvent::create([
            'server_id' => $server->id,
            'match_id' => $finished->id,
            'event_type' => 'match_end',
            'occurred_at' => now(),
        ]);

        StatsRecalculator::recalculateAll();

        $attacker->refresh();
        $this->assertSame(1, $attacker->kills_total);
    }
}
