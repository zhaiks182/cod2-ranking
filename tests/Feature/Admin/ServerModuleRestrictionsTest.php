<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El modulo "servers" (2026-09-01, seguimiento del sistema de roles del
 * 2026-08-31) es SOLO consola RCON + ver la lista de servers reales -- crear,
 * editar o borrar un server (toca la contraseña RCON de produccion) quedo
 * reservado a super-admin. "hosted-servers" (servidores temporales) y el form
 * de retencion de demos (gateado por "demos") se separaron de "servers" el
 * mismo dia -- antes los tres vivian bajo el mismo checkbox. Ver
 * User::MODULES y CLAUDE.md.
 */
class ServerModuleRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    private function serverModuleUser(): User
    {
        return User::factory()->create(['is_super_admin' => false, 'permissions' => ['servers']]);
    }

    public function test_a_servers_module_user_can_view_the_server_list_and_console(): void
    {
        $user = $this->serverModuleUser();
        $server = Server::first();

        $this->actingAs($user)->get(route('admin.servers.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.console.show', $server))->assertOk();
    }

    public function test_a_servers_module_user_cannot_create_a_server(): void
    {
        $user = $this->serverModuleUser();

        $this->actingAs($user)->get(route('admin.servers.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.servers.store'), [])->assertForbidden();
    }

    public function test_a_servers_module_user_cannot_edit_or_delete_a_server(): void
    {
        $user = $this->serverModuleUser();
        $server = Server::first();

        $this->actingAs($user)->get(route('admin.servers.edit', $server))->assertForbidden();
        $this->actingAs($user)->put(route('admin.servers.update', $server), [])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.servers.destroy', $server))->assertForbidden();

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_the_servers_index_page_hides_edit_and_delete_for_a_non_super_admin(): void
    {
        $user = $this->serverModuleUser();

        $response = $this->actingAs($user)->get(route('admin.servers.index'));

        $response->assertOk();
        $response->assertDontSee('Editar', false);
        $response->assertDontSee('href="'.route('admin.servers.create').'"', false);
    }

    public function test_a_super_admin_can_create_edit_and_delete_a_server(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $server = Server::first();

        $this->actingAs($admin)->get(route('admin.servers.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.servers.edit', $server))->assertOk();
    }

    public function test_a_servers_module_user_cannot_manage_hosted_servers(): void
    {
        $user = $this->serverModuleUser();

        $this->actingAs($user)->get(route('admin.hosted-servers.index'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.hosted-servers.update'), ['hosted_servers_ports' => ['28970']])->assertForbidden();
    }

    public function test_a_hosted_servers_module_user_can_manage_hosted_servers_without_the_servers_module(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['hosted-servers']]);

        $this->actingAs($user)->get(route('admin.hosted-servers.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.servers.index'))->assertForbidden();
    }

    public function test_a_servers_module_user_cannot_save_demo_retention_settings(): void
    {
        $user = $this->serverModuleUser();

        $this->actingAs($user)->put(route('admin.settings.update'), ['demo_retention_days' => 10])->assertForbidden();
    }

    public function test_a_demos_module_user_can_save_demo_retention_settings(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['demos']]);

        $this->actingAs($user)->put(route('admin.settings.update'), ['demo_retention_days' => 10])->assertRedirect();
    }
}
