<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\Server;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerMatchExtraTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_resolve_to_the_right_player_and_match(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $match = GameMatch::create([
            'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        $extra = PlayerMatchExtra::create([
            'player_id' => $player->id, 'match_id' => $match->id,
            'bomb_plants' => 2, 'bomb_defuses' => 1, 'damage_dealt' => 150, 'damage_taken' => 50, 'mid_round_disconnects' => 0,
        ]);

        $this->assertSame($player->id, $extra->player->id);
        $this->assertSame($match->id, $extra->match->id);
    }

    public function test_rejects_a_duplicate_player_and_match_pair(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $match = GameMatch::create([
            'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $match->id, 'bomb_plants' => 1]);

        $this->expectException(QueryException::class);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $match->id, 'bomb_plants' => 1]);
    }
}
