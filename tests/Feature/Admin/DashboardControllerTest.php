<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel de inicio de /adm_cod2 (2026-08-31, a pedido del dueño) -- antes
 * "/adm_cod2" solo redirigia a la lista de servidores, sin ningun resumen.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('admin.home'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_shows_real_counts_and_recent_actions(): void
    {
        $admin = User::factory()->create();
        Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server-dash', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28961, 'rcon_password' => 'x',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28961, 'max_clients' => 30, 'is_active' => true,
        ]);
        GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);
        AdminAction::record('players.destroy', 'Borró al jugador Test (guid 123)');

        $response = $this->actingAs($admin)->get(route('admin.home'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['players_total'] === 2 && $stats['matches_today'] === 1);
        $response->assertSee('Test Server');
        $response->assertSee('Borró al jugador Test');
    }
}
