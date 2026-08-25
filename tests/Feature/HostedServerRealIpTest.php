<?php

namespace Tests\Feature;

use App\Models\HostedServer;
use App\Models\Setting;
use App\Support\HostedServerProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El sitio esta detras de Cloudflare (proxy) -- sin trustProxies() configurado,
 * $request->ip() siempre devuelve un borde de Cloudflare, nunca el visitante real
 * (confirmado en produccion: dos servidores temporales creados por la misma persona
 * quedaron con creator_ip=172.68.125.137, un rango de Cloudflare). Estos tests
 * ejercitan el comportamiento real a traves del flujo de creacion de servidores
 * temporales -- no un endpoint sintetico -- porque es ahi donde el bug se manifiesta
 * y donde el limite de "1 por IP" (HostedServerOnePerIpTest) necesita la IP real
 * para funcionar.
 */
class HostedServerRealIpTest extends TestCase
{
    use RefreshDatabase;

    private function activeHostedServer(string $ip): HostedServer
    {
        return HostedServer::create([
            'hostname' => 'Existing @ Pug Latam',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'rcon_password' => 'secret',
            'management_token' => bin2hex(random_bytes(20)),
            'status' => 'running',
            'port' => 28970,
            'expires_at' => now()->addHours(3),
            'creator_ip' => $ip,
        ]);
    }

    /** Ver el comentario equivalente en HostedServerOnePerIpTest. */
    private function validFormData(): array
    {
        return [
            'hostname' => 'Test Server',
            'slots' => 8,
            'map' => 'mp_toujane_fix',
            'join_password' => 'secret123',
        ];
    }

    /** Ver el comentario equivalente en HostedServerOnePerIpTest. */
    private function mockSuccessfulProvisioning(): HostedServer
    {
        // Ver el comentario equivalente en HostedServerOnePerIpTest sobre por que
        // hace falta esto (el fixture + este server "simulado" suman 2 filas
        // activas, que sin esto chocan contra el tope de concurrencia).
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

    public function test_trusts_x_forwarded_for_when_the_real_peer_is_a_cloudflare_ip(): void
    {
        $existing = $this->activeHostedServer('203.0.113.9');

        $response = $this->call('POST', route('hosted-servers.store'), $this->validFormData(), [], [], [
            'REMOTE_ADDR' => '172.68.125.137', // rango real de Cloudflare (172.64.0.0/13)
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        // No usa assertRedirect($uri) a proposito: si la asercion fallara (por
        // ejemplo mientras este test esta en RED, redirigiendo a otro lado por
        // validacion fallida), el helper de Laravel intenta decorar el mensaje
        // leyendo session('errors')->all() y revienta con "Call to a member
        // function all() on array" en este tipo de request armado con call() +
        // $server custom -- comparar el header Location a mano evita ese bug del
        // framework de testing y da un mensaje de fallo legible de verdad.
        $response->assertStatus(302);
        $this->assertSame(
            route('hosted-servers.show', [$existing, $existing->management_token]),
            $response->headers->get('Location')
        );
    }

    public function test_ignores_x_forwarded_for_when_the_real_peer_is_not_cloudflare(): void
    {
        $existing = $this->activeHostedServer('203.0.113.9');
        $created = $this->mockSuccessfulProvisioning();

        $response = $this->call('POST', route('hosted-servers.store'), $this->validFormData(), [], [], [
            'REMOTE_ADDR' => '8.8.8.8', // no es Cloudflare
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9', // intento de spoof, debe ignorarse
        ]);

        // El spoof no debe colar -- $request->ip() resuelve a 8.8.8.8 (el REMOTE_ADDR
        // real), que no coincide con el server existente (203.0.113.9), asi que el
        // flujo sigue de largo y crea uno nuevo en vez de redirigir al de otro.
        $response->assertStatus(302);
        $this->assertSame(
            route('hosted-servers.show', [$created, $created->management_token]),
            $response->headers->get('Location')
        );
        $this->assertNotSame(
            route('hosted-servers.show', [$existing, $existing->management_token]),
            $response->headers->get('Location')
        );
    }
}
