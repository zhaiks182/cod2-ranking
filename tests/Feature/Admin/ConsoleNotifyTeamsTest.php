<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boton "Notificar Discord" del balanceo de equipos en /adm_cod2/console/{server}
 * (2026-09-01) -- ConsoleController::notifyTeams() toca RCON real
 * (Cod2RconClient::status(), fsockopen UDP) igual que el resto de
 * ConsoleController (kick/ban/message/map/command/service), que
 * deliberadamente no tiene tests de feature por el mismo motivo (ver
 * CLAUDE.md, no hay ningun test de esos otros endpoints) -- se verifica en
 * vivo contra el server real en vez de mockear un socket UDP. Este test
 * solo cubre lo que SÍ es seguro probar sin RCON: la ruta esta protegida.
 */
class ConsoleNotifyTeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $server = Server::first();

        $response = $this->post(route('admin.console.notify-teams', $server));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_a_user_without_the_servers_module_is_forbidden(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['demos']]);
        $server = Server::first();

        $response = $this->actingAs($user)->post(route('admin.console.notify-teams', $server));

        $response->assertForbidden();
    }
}
