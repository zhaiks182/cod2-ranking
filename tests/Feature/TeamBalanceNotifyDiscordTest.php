<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boton publico "Notificar Discord" de /equipos (2026-09-01) -- a pedido
 * explicito del dueño, sin sesion admin (a diferencia de
 * Admin\ConsoleController::notifyTeams(), ver ConsoleNotifyTeamsTest).
 * TeamBalanceController::notifyDiscord() toca RCON real (fsockopen UDP)
 * igual que el resto de acciones que hablan con el gameserver -- mismo
 * criterio ya establecido en este proyecto de no mockear ese socket, solo
 * se cubre lo que si es seguro probar sin RCON real.
 */
class TeamBalanceNotifyDiscordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_hit_the_route_without_being_redirected_to_any_login(): void
    {
        $response = $this->post(route('team-balance.notify'), ['server' => 'no-existe']);

        $response->assertRedirect();
        $this->assertNotEquals(route('admin.login'), $response->headers->get('Location'));
        $this->assertNotEquals(route('login'), $response->headers->get('Location'));
    }

    public function test_an_unknown_server_slug_fails_gracefully_with_an_error_message(): void
    {
        $response = $this->from(route('team-balance'))->post(route('team-balance.notify'), ['server' => 'no-existe']);

        $response->assertRedirect(route('team-balance'));
        $response->assertSessionHas('error', 'Servidor no encontrado.');
    }

    public function test_the_server_field_is_required(): void
    {
        $response = $this->post(route('team-balance.notify'), []);

        $response->assertSessionHasErrors('server');
    }

    public function test_an_inactive_server_is_treated_as_not_found(): void
    {
        $server = Server::first();
        $server->update(['is_active' => false, 'slug' => 'inactivo-test']);

        $response = $this->from(route('team-balance'))->post(route('team-balance.notify'), ['server' => 'inactivo-test']);

        $response->assertRedirect(route('team-balance'));
        $response->assertSessionHas('error', 'Servidor no encontrado.');
    }
}
