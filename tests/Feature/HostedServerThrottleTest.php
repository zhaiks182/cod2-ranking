<?php

namespace Tests\Feature;

use Tests\TestCase;

class HostedServerThrottleTest extends TestCase
{
    /**
     * El formulario de creacion de servidores temporales no tiene login ni
     * CSRF-exempt especial -- Laravel ya inyecta el token via el helper
     * $this->post() de las feature tests, asi que no hace falta armarlo a mano.
     *
     * Se manda el POST vacio a proposito: la validacion de
     * StoreHostedServerRequest (hostname requerido) lo va a rechazar con un
     * 302 (redirect back con errores) ANTES de llegar a la logica real del
     * controller (Turnstile, lock de concurrencia, HostedServerProvisioner) --
     * lo unico que este test necesita confirmar es que el propio middleware
     * throttle corta en el intento 21, sin que la request tenga que ser
     * valida ni disparar systemctl/RCON de verdad.
     */
    public function test_throttles_after_20_attempts_in_60_seconds(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $response = $this->post('/servidores/crear', []);
            $response->assertStatus(302);
        }

        $response = $this->post('/servidores/crear', []);
        $response->assertStatus(429);
    }

    public function test_allows_requests_under_the_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/servidores/crear', []);
            $response->assertStatus(302);
        }
    }
}
