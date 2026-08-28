<?php

namespace Tests\Feature\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Models\Round;
use App\Models\Server;
use App\Support\PlayerMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Caso real (2026-08-28): "MOKOS"/"~Vt'$~Dav1Ds'" con varios guid distintos del
 * mismo jugador (HWID inestable entre sesiones -- ver CLAUDE.md). El admin
 * confirma a mano (alias/chat/IP) que son la misma persona y fusiona.
 */
class PlayerMergerTest extends TestCase
{
    use RefreshDatabase;

    private function makePlayer(array $overrides = []): Player
    {
        return Player::create(array_merge([
            'guid' => random_int(-2000000000, 2000000000),
            'last_name' => 'Test',
            'last_name_plain' => 'Test',
            'kills_total' => 0,
            'deaths_total' => 0,
            'headshots_total' => 0,
            'grenade_kills_total' => 0,
            'suicides_total' => 0,
        ], $overrides));
    }

    public function test_sums_lifetime_totals_and_deletes_the_source(): void
    {
        $target = $this->makePlayer(['guid' => 111, 'last_name' => 'MOKOS RELOAD', 'last_name_plain' => 'MOKOS RELOAD', 'kills_total' => 115, 'deaths_total' => 298]);
        $source = $this->makePlayer(['guid' => 222, 'last_name' => 'MOKOS', 'last_name_plain' => 'MOKOS', 'kills_total' => 11, 'deaths_total' => 59]);

        PlayerMerger::merge([$source->id], $target->id);

        $target->refresh();
        $this->assertSame(126, $target->kills_total);
        $this->assertSame(357, $target->deaths_total);
        $this->assertDatabaseMissing('players', ['id' => $source->id]);
    }

    public function test_merges_three_sources_into_one_target(): void
    {
        $target = $this->makePlayer(['guid' => 60, 'kills_total' => 115]);
        $s1 = $this->makePlayer(['guid' => 16, 'kills_total' => 122]);
        $s2 = $this->makePlayer(['guid' => 50, 'kills_total' => 11]);

        PlayerMerger::merge([$s1->id, $s2->id], $target->id);

        $target->refresh();
        $this->assertSame(248, $target->kills_total);
        $this->assertSame(1, Player::count());
    }

    public function test_repoints_kills_without_rewriting_the_historical_guid_or_name(): void
    {
        $target = $this->makePlayer();
        $source = $this->makePlayer();

        $server = Server::create(['name' => 'S', 'slug' => 's', 'log_path' => '/tmp/x.log', 'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'x', 'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true]);
        $match = GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);
        $round = Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);

        $kill = Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $source->id, 'attacker_guid' => $source->guid, 'attacker_name' => 'OldName',
            'victim_player_id' => null, 'victim_guid' => 0, 'victim_name' => 'Bot',
            'weapon' => 'M1Garand', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false,
            'occurred_at' => now(),
        ]);

        PlayerMerger::merge([$source->id], $target->id);

        $kill->refresh();
        $this->assertSame($target->id, $kill->attacker_player_id);
        $this->assertSame($source->guid, $kill->attacker_guid);
        $this->assertSame('OldName', $kill->attacker_name);
    }

    public function test_sums_map_stats_instead_of_violating_the_unique_constraint(): void
    {
        $target = $this->makePlayer();
        $source = $this->makePlayer();
        $server = Server::create(['name' => 'S', 'slug' => 's', 'log_path' => '/tmp/x.log', 'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'x', 'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true]);

        DB::table('player_map_stats')->insert([
            ['player_id' => $target->id, 'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'kills' => 10, 'teamkills' => 0, 'deaths' => 5, 'headshots' => 1, 'grenade_kills' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('player_map_stats')->insert([
            ['player_id' => $source->id, 'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'kills' => 3, 'teamkills' => 1, 'deaths' => 2, 'headshots' => 0, 'grenade_kills' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['player_id' => $source->id, 'server_id' => $server->id, 'map' => 'mp_burgundy', 'kills' => 4, 'teamkills' => 0, 'deaths' => 1, 'headshots' => 0, 'grenade_kills' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        PlayerMerger::merge([$source->id], $target->id);

        $this->assertSame(1, DB::table('player_map_stats')->where(['player_id' => $target->id, 'server_id' => $server->id, 'map' => 'mp_toujane_fix'])->count());
        $this->assertSame(13, DB::table('player_map_stats')->where(['player_id' => $target->id, 'server_id' => $server->id, 'map' => 'mp_toujane_fix'])->value('kills'));
        $this->assertSame(4, DB::table('player_map_stats')->where(['player_id' => $target->id, 'server_id' => $server->id, 'map' => 'mp_burgundy'])->value('kills'));
        $this->assertSame(0, DB::table('player_map_stats')->where('player_id', $source->id)->count());
    }

    public function test_merges_aliases_deduping_by_exact_name_and_keeping_the_latest_last_seen(): void
    {
        $target = $this->makePlayer();
        $source = $this->makePlayer();

        PlayerAlias::create(['player_id' => $target->id, 'name' => 'MOKOS', 'name_plain' => 'MOKOS', 'last_seen_at' => now()->subDays(3)]);
        PlayerAlias::create(['player_id' => $source->id, 'name' => 'MOKOS', 'name_plain' => 'MOKOS', 'last_seen_at' => now()]);
        PlayerAlias::create(['player_id' => $source->id, 'name' => 'MOKOS RELOAD', 'name_plain' => 'MOKOS RELOAD', 'last_seen_at' => now()]);

        PlayerMerger::merge([$source->id], $target->id);

        $target->refresh();
        $this->assertCount(2, $target->aliases);
        $mokosAlias = $target->aliases->firstWhere('name', 'MOKOS');
        $this->assertTrue($mokosAlias->last_seen_at->gt(now()->subMinute()));
    }
}
