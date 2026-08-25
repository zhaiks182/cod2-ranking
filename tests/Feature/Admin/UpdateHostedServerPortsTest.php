<?php

namespace Tests\Feature\Admin;

use App\Models\HostedServer;
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

    public function test_admin_can_save_a_valid_port_list(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.hosted-servers.update'), [
                'hosted_servers_ports' => '28970, 28980, 28990',
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
            ->put(route('admin.settings.hosted-servers.update'), [
                'hosted_servers_ports' => '28970,28970',
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980], Setting::current()->hostedServerPorts());
    }

    public function test_rejects_a_port_outside_valid_range(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.settings.hosted-servers.update'), [
                'hosted_servers_ports' => '80,28980',
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980], Setting::current()->hostedServerPorts());
    }

    public function test_rejects_an_empty_list(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $this->actingAs($admin)
            ->put(route('admin.settings.hosted-servers.update'), [
                'hosted_servers_ports' => '',
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
            ->put(route('admin.settings.hosted-servers.update'), [
                'hosted_servers_ports' => '28970,28990',
            ])
            ->assertSessionHasErrors('hosted_servers_ports');

        $this->assertSame([28970, 28980, 28990], Setting::current()->hostedServerPorts());
        $this->assertDatabaseHas('hosted_servers', ['id' => $active->id, 'port' => 28980]);
    }
}
