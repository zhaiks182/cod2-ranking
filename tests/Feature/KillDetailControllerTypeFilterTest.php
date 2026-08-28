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
 * Pedido de un jugador (2026-08-28): "al dar click en el valor de granadas [o
 * headshots] a quien mate... debe ser igual que las kills". Reusa el mismo
 * popover/endpoint (/kills/{guid}) que ya usa la columna Kills, con
 * ?type=headshot|grenade acotando el resultado -- no un endpoint nuevo.
 */
class KillDetailControllerTypeFilterTest extends TestCase
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

    private function realMatchWithKill(Player $attacker, Player $victim, bool $isHeadshot, bool $isGrenade): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
            'weapon' => $isGrenade ? 'frag_grenade_mp' : 'weapon_mp44', 'damage' => 100,
            'mod' => $isGrenade ? 'MOD_GRENADE' : 'MOD_RIFLE_BULLET', 'is_headshot' => $isHeadshot, 'is_grenade' => $isGrenade,
            'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_type_headshot_only_returns_headshot_kills(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $headshotVictim = Player::create(['guid' => 222, 'last_name' => 'HeadshotVictim', 'last_name_plain' => 'HeadshotVictim']);
        $normalVictim = Player::create(['guid' => 333, 'last_name' => 'NormalVictim', 'last_name_plain' => 'NormalVictim']);

        $this->realMatchWithKill($attacker, $headshotVictim, isHeadshot: true, isGrenade: false);
        $this->realMatchWithKill($attacker, $normalVictim, isHeadshot: false, isGrenade: false);

        $response = $this->getJson(route('kills.detail', [$attacker->guid, 'type' => 'headshot', 'season' => 'all']));

        $response->assertOk();
        $victims = collect($response->json())->pluck('victim');
        $this->assertTrue($victims->contains('HeadshotVictim'));
        $this->assertFalse($victims->contains('NormalVictim'));
    }

    public function test_type_grenade_only_returns_grenade_kills(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $grenadeVictim = Player::create(['guid' => 222, 'last_name' => 'GrenadeVictim', 'last_name_plain' => 'GrenadeVictim']);
        $normalVictim = Player::create(['guid' => 333, 'last_name' => 'NormalVictim', 'last_name_plain' => 'NormalVictim']);

        $this->realMatchWithKill($attacker, $grenadeVictim, isHeadshot: false, isGrenade: true);
        $this->realMatchWithKill($attacker, $normalVictim, isHeadshot: false, isGrenade: false);

        $response = $this->getJson(route('kills.detail', [$attacker->guid, 'type' => 'grenade', 'season' => 'all']));

        $response->assertOk();
        $victims = collect($response->json())->pluck('victim');
        $this->assertTrue($victims->contains('GrenadeVictim'));
        $this->assertFalse($victims->contains('NormalVictim'));
    }

    public function test_without_type_param_behaves_like_before(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $headshotVictim = Player::create(['guid' => 222, 'last_name' => 'HeadshotVictim', 'last_name_plain' => 'HeadshotVictim']);
        $normalVictim = Player::create(['guid' => 333, 'last_name' => 'NormalVictim', 'last_name_plain' => 'NormalVictim']);

        $this->realMatchWithKill($attacker, $headshotVictim, isHeadshot: true, isGrenade: false);
        $this->realMatchWithKill($attacker, $normalVictim, isHeadshot: false, isGrenade: false);

        $response = $this->getJson(route('kills.detail', [$attacker->guid, 'season' => 'all']));

        $response->assertOk();
        $victims = collect($response->json())->pluck('victim');
        $this->assertTrue($victims->contains('HeadshotVictim'));
        $this->assertTrue($victims->contains('NormalVictim'));
    }

    public function test_reverse_count_also_respects_the_type_filter(): void
    {
        $a = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 222, 'last_name' => 'B', 'last_name_plain' => 'B']);

        // a headshots b once; b kills a back twice, but NEITHER of those are headshots.
        $this->realMatchWithKill($a, $b, isHeadshot: true, isGrenade: false);
        $this->realMatchWithKill($b, $a, isHeadshot: false, isGrenade: false);
        $this->realMatchWithKill($b, $a, isHeadshot: false, isGrenade: false);

        $response = $this->getJson(route('kills.detail', [$a->guid, 'type' => 'headshot', 'season' => 'all']));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('victim', 'B');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['reverse'], 'B never headshotted A back, so the type-scoped reverse count must be 0, not 2.');
    }
}
