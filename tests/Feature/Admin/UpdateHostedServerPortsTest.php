<?php

namespace Tests\Feature\Admin;

use App\Models\HostedServer;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateHostedServerPortsTest extends TestCase
{
    use RefreshDatabase;

    private function activeHostedServer(int $port): HostedServer
    {
        return HostedServer::create([
            'hostname' => 'Test Server',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'rcon_password' => 'secret',
            'management_token' => bin2hex(random_bytes(20)),
            'status' => 'running',
            'port' => $port,
            'expires_at' => now()->addHours(3),
            'creator_ip' => '127.0.0.1',
        ]);
    }

    /**
     * La migracion 2026_08_10_090005_seed_default_server_and_backfill.php ya
     * siembra un server real ("Pug Latam") en cada migrate/RefreshDatabase --
     * no hace falta crear uno nuevo (chocaria con el slug unico), alcanza con
     * ajustar su puerto/estado para el escenario del test.
     */
    private function realServer(int $connectPort, bool $active = true): Server
    {
        $server = Server::first();
        $server->update(['connect_port' => $connectPort, 'rcon_port' => $connectPort, 'is_active' => $active]);

        return $server;
    }

    public function test_admin_can_save_a_valid_port_list(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['28970', '28980', '28990'],
            ])
            ->assertRedirect();

        $this->assertSame([28970, 28980, 28990], Setting::current()->hostedServerPorts());
        $this->assertSame(3, Setting::maxConcurrent());
    }

    public function test_rejects_duplicate_ports(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['28970', '28970'],
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980], Setting::current()->hostedServerPorts());
    }

    public function test_rejects_a_port_outside_valid_range(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['80', '28980'],
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980], Setting::current()->hostedServerPorts());
    }

    public function test_rejects_an_empty_list(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => [],
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980], Setting::current()->hostedServerPorts());
    }

    public function test_rejects_removing_a_port_with_an_active_server(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);
        $active = $this->activeHostedServer(28980);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['28970', '28990'],
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980, 28990], Setting::current()->hostedServerPorts());
        $this->assertDatabaseHas('hosted_servers', ['id' => $active->id, 'port' => 28980]);
    }

    /** Con menos filas de puerto que servidores activos igual se puede reducir, mientras ninguno de los puertos activos se saque de la lista nueva. */
    public function test_allows_shrinking_the_list_when_no_active_port_is_removed(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);
        $active = $this->activeHostedServer(28970);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['28970'],
            ])
            ->assertRedirect();

        $this->assertSame([28970], Setting::current()->hostedServerPorts());
        $this->assertDatabaseHas('hosted_servers', ['id' => $active->id, 'port' => 28970]);
    }

    public function test_rejects_a_port_used_by_a_real_server(): void
    {
        $admin = User::factory()->create();
        $this->realServer(28960);
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['28960', '28980'],
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980], Setting::current()->hostedServerPorts());
    }

    /** Un server real inactivo (is_active=false) ya no compite por el puerto -- no bloquea. */
    public function test_allows_a_port_used_by_an_inactive_real_server(): void
    {
        $admin = User::factory()->create();
        $this->realServer(28960, active: false);
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.hosted-servers.update'), [
                'hosted_servers_ports' => ['28960', '28980'],
            ])
            ->assertRedirect();

        $this->assertSame([28960, 28980], Setting::current()->hostedServerPorts());
    }
}
