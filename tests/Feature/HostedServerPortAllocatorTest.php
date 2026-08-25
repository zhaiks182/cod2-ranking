<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\HostedServerPortAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostedServerPortAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private function baseAttributes(): array
    {
        return [
            'hostname' => 'Test Server',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'rcon_password' => 'secret',
            'management_token' => bin2hex(random_bytes(20)),
            'expires_at' => now()->addHours(3),
            'creator_ip' => '127.0.0.1',
        ];
    }

    public function test_allocates_from_the_configured_port_list(): void
    {
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);

        $server = (new HostedServerPortAllocator)->allocate($this->baseAttributes());

        $this->assertSame(28970, $server->port);
    }

    public function test_skips_a_port_already_taken(): void
    {
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990']);
        (new HostedServerPortAllocator)->allocate($this->baseAttributes());

        $second = (new HostedServerPortAllocator)->allocate($this->baseAttributes());

        $this->assertSame(28980, $second->port);
    }

    public function test_throws_when_configured_ports_are_exhausted(): void
    {
        Setting::current()->update(['hosted_servers_ports' => '28970']);
        (new HostedServerPortAllocator)->allocate($this->baseAttributes());

        $this->expectException(\RuntimeException::class);

        (new HostedServerPortAllocator)->allocate($this->baseAttributes());
    }
}
