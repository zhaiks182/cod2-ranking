<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostedServerThrottleTest extends TestCase
{
    use RefreshDatabase;
    /**
     * El formulario de creacion de servidores temporales no tiene login.
     * Sobre CSRF: ValidateCsrfToken middleware (o validateCsrfTokens en
     * bootstrap/app.php) detecta que corren bajo PHPUnit ($this->app->runningUnitTests())
     * y simplemente salta la validacion de CSRF -- no se inyecta token, CSRF
     * se desactiva por completo en test. Esto es correcto para testing local,
     * pero un curl real contra la ruta (fuera de PHPUnit, en producción) SÍ
     * requiere un token CSRF válido + cookie de sesión.
     *
     * Se manda el POST vacio a proposito: la validacion de
     * StoreHostedServerRequest (hostname requerido) lo va a rechazar con un
     * 302 (redirect back con errores) ANTES de llegar a la logica real del
     * controller (Turnstile, lock de concurrencia, HostedServerProvisioner) --
     * lo unico que este test necesita confirmar es que el propio middleware
     * throttle corta en el intento 21, sin que la request tenga que ser
     * valida ni disparar systemctl/RCON de verdad.
     */
    public function test_throttles_after_20_attempts_per_minute(): void
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

    public function test_create_form_page_is_not_throttled(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $response = $this->get('/servidores/crear');
            $response->assertStatus(200);
        }
    }
}
