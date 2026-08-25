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
        $response->assertSee('value="28970"', false);
        $response->assertSee('value="28980"', false);
        $response->assertSee('value="28990"', false);
        $response->assertSee('Servidor temporal #1', false);
        $response->assertSee('Servidor temporal #3', false);
        $response->assertSee('0/3 activos', false);
    }

    public function test_has_add_and_remove_controls_instead_of_a_count_stepper(): void
    {
        $admin = User::factory()->create();
        Setting::current()->update(['hosted_servers_ports' => '28970,28980']);

        $response = $this->actingAs($admin)->get(route('admin.servers.index'));

        $response->assertOk();
        $response->assertSee('+ Agregar servidor temporal', false);
        $response->assertSee('Quitar este servidor temporal', false);
        $response->assertDontSee('hosted_servers_count', false);
    }

    public function test_hosted_servers_section_appears_after_the_real_servers_table(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.servers.index'));

        $response->assertOk();

        $content = $response->getContent();
        $realServersHeading = strpos($content, 'Nombre');
        $hostedServersHeading = strpos($content, 'Servidores temporales (self-service)');

        $this->assertNotFalse($realServersHeading);
        $this->assertNotFalse($hostedServersHeading);
        $this->assertLessThan($hostedServersHeading, $realServersHeading, 'La tabla de servidores reales debe aparecer antes de la seccion de servidores temporales.');
    }
}
