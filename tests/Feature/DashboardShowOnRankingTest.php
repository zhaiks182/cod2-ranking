<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerServerStat;
use App\Models\Server;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mismo comportamiento que LeaderboardShowOnRankingTest, pero para el top de
 * jugadores conectados de la home (DashboardController::loadServerData()).
 */
class DashboardShowOnRankingTest extends TestCase
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

    public function test_a_player_with_show_on_ranking_false_is_excluded_from_the_home_top_players(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'kills' => 100, 'deaths' => 10]);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id, 'show_on_ranking' => false]);

        $response = $this->get(route('dashboard', ['server' => $this->server->slug]));

        $response->assertOk();
        $names = collect($response->viewData('topPlayers'))->pluck('player.last_name_plain');
        $this->assertNotContains('Attacker', $names);
    }

    public function test_a_claimed_player_without_opting_out_still_appears_in_the_home_top_players(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'kills' => 100, 'deaths' => 10]);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $response = $this->get(route('dashboard', ['server' => $this->server->slug]));

        $names = collect($response->viewData('topPlayers'))->pluck('player.last_name_plain');
        $this->assertContains('Attacker', $names);
    }
}
