<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * hosted_servers_ports reemplaza a hosted_servers_max_concurrent como fuente de
 * verdad -- el limite de servidores temporales concurrentes pasa a ser
 * simplemente "cuantos puertos hay en la lista", para que nunca puedan
 * desincronizarse dos numeros distintos (ver CLAUDE.md, "Servidores temporales
 * self-service").
 */
class SettingHostedServerPortsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hosted_server_ports_parses_comma_separated_string(): void
    {
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);

        $this->assertSame([28970, 28980, 28990], Setting::current()->hostedServerPorts());
    }

    public function test_hosted_server_ports_falls_back_to_config_range_when_unset(): void
    {
        config([
            'hosted_servers.port_range_start' => 28970,
            'hosted_servers.max_concurrent' => 2,
        ]);

        $this->assertSame([28970, 28971], Setting::current()->hostedServerPorts());
    }

    public function test_max_concurrent_equals_port_count(): void
    {
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);

        $this->assertSame(3, Setting::maxConcurrent());
    }
}
