<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerIndexShowsHostedServerPortsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_configured_port_list_and_the_derived_max(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);

        $response = $this->actingAs($admin)->get(route('admin.servers.index'));

        $response->assertOk();
        $response->assertSee('value="3"', false); // cantidad de servidores temporales
        $response->assertSee('value="28970"', false);
        $response->assertSee('value="28980"', false);
        $response->assertSee('value="28990"', false);
        $response->assertSee('Servidor temporal #1', false);
        $response->assertSee('Servidor temporal #3', false);
        $response->assertSee('3 máximo', false);
    }
}
