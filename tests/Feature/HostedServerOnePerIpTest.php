<?php

namespace Tests\Feature;

use App\Models\HostedServer;
use App\Models\Setting;
use App\Support\HostedServerProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HostedServerOnePerIpTest extends TestCase
{
    use RefreshDatabase;

    private function activeHostedServer(string $ip, string $status = 'running'): HostedServer
    {
        return HostedServer::create([
            'hostname' => 'Existing @ Pug Latam',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'rcon_password' => 'secret',
            'management_token' => bin2hex(random_bytes(20)),
            'status' => $status,
            'port' => 28970,
            'expires_at' => now()->addHours(3),
            'creator_ip' => $ip,
        ]);
    }

    /**
     * StoreHostedServerRequest valida ANTES de que corra el cuerpo de store()
     * (es un FormRequest inyectado en la firma del metodo) -- un payload
     * invalido/vacio nunca llega a ejecutar el chequeo de IP duplicada. Estos
     * datos son los minimos que pasan esa validacion.
     */
    private function validFormData(): array
    {
        return [
            'hostname' => 'Test Server',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'join_password' => 'secret123',
        ];
    }

    /**
     * Ademas de pasar la validacion del form, un request que NO se bloquea por
     * IP duplicada sigue de largo hasta HostedServerProvisioner::provision(),
     * que en la vida real escribe archivos y corre `sudo systemctl start` --
     * nada de eso debe ejecutarse de verdad en un test (correrian contra el
     * VPS real de todos modos, sea o no el mismo que produccion). Se mockea el
     * provisioner para simular una creacion exitosa sin tocar el sistema.
     */
    private function mockSuccessfulProvisioning(): HostedServer
    {
        // El fixture de "server ya existente" (creado en cada test antes de esto)
        // mas este server "creado por la simulacion" suman 2 filas activas -- sin
        // esto, el tope de concurrencia (Setting::maxConcurrent(), default 2) las
        // bloquea a las dos antes de que el chequeo de IP duplicada tenga chance de
        // importar. No es lo que este test quiere ejercitar, asi que se le da
        // margen de sobra.
        Setting::current()->update(['hosted_servers_ports' => '28970,28980,28990,29000,29010']);

        $created = HostedServer::create([
            'hostname' => 'Test Server @ Pug Latam',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'rcon_password' => 'secret',
            'management_token' => bin2hex(random_bytes(20)),
            'status' => 'running',
            'port' => 28980,
            'expires_at' => now()->addHours(3),
            'creator_ip' => '0.0.0.0',
        ]);

        $this->mock(HostedServerProvisioner::class, function ($mock) use ($created) {
            $mock->shouldReceive('provision')->once()->andReturn($created);
        });

        return $created;
    }

    public function test_redirects_to_the_existing_active_server_for_the_same_ip(): void
    {
        $existing = $this->activeHostedServer('198.51.100.7');

        $response = $this->call('POST', route('hosted-servers.store'), $this->validFormData(), [], [], [
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        // Ver el comentario en HostedServerRealIpTest sobre por que no se usa
        // assertRedirect($uri) aca -- mismo bug del helper de testing de Laravel.
        $response->assertStatus(302);
        $this->assertSame(
            route('hosted-servers.show', [$existing, $existing->management_token]),
            $response->headers->get('Location')
        );
    }

    public function test_allows_creating_from_a_different_ip(): void
    {
        $this->activeHostedServer('198.51.100.7');
        $created = $this->mockSuccessfulProvisioning();

        $response = $this->call('POST', route('hosted-servers.store'), $this->validFormData(), [], [], [
            'REMOTE_ADDR' => '198.51.100.8',
        ]);

        $response->assertStatus(302);
        $this->assertSame(
            route('hosted-servers.show', [$created, $created->management_token]),
            $response->headers->get('Location')
        );
    }

    #[DataProvider('inactiveStatuses')]
    public function test_allows_creating_when_the_existing_server_for_that_ip_is_no_longer_active(string $status): void
    {
        $this->activeHostedServer('198.51.100.7', $status);
        $created = $this->mockSuccessfulProvisioning();

        $response = $this->call('POST', route('hosted-servers.store'), $this->validFormData(), [], [], [
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertStatus(302);
        $this->assertSame(
            route('hosted-servers.show', [$created, $created->management_token]),
            $response->headers->get('Location')
        );
    }

    public static function inactiveStatuses(): array
    {
        return [['stopped'], ['expired'], ['failed']];
    }
}
