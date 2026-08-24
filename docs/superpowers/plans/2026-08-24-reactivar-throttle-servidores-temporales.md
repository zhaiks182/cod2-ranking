# Reactivar throttle en /servidores/crear Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Volver a limitar cuántas veces por minuto se puede hacer `POST /servidores/crear`, cerrando el gap de abuso que quedó abierto desde que se sacó el throttle el 2026-08-22 (commit `4ebfd16`) para pruebas del dueño.

**Architecture:** Un solo cambio de una línea en `routes/web.php` (agregar el middleware `throttle:20,60` a la ruta `POST /servidores/crear`) más un feature test que confirma que el límite dispara un `429` después de 20 intentos en la misma ventana de 60s. Turnstile (verificación de humano) y el `Cache::lock` de concurrencia global (tope de servers activos) siguen siendo las otras dos capas de protección — el throttle es la tercera, específicamente contra ráfagas rápidas de requests desde una sola IP/sesión.

**Tech Stack:** Laravel 13, PHPUnit (feature test contra rutas reales), middleware `throttle` nativo de Laravel (`Illuminate\Routing\Middleware\ThrottleRequests`, basado en `RateLimiter` + el store de cache configurado).

**Spec:** Sin spec formal aparte — la justificación completa (por qué se sacó, por qué reconsiderar, y el valor `throttle:20,60` sugerido) ya está documentada en `CLAUDE.md` del repo, sección "Mitigación de abuso" y en el comentario actual de `routes/web.php:83-88`. Este plan implementa esa reconsideración ya acordada.

## Global Constraints

- Límite exacto a aplicar: `throttle:20,60` (20 requests cada 60 segundos, por IP+ruta — comportamiento default de Laravel) — es el valor que el propio comentario en `routes/web.php` ya sugería como reemplazo del `throttle:3,60` original, sin volver a ser tan estricto como para bloquear pruebas normales de un usuario real.
- No tocar `Route::get('/crear', ...)` (la página del formulario) — el throttle va solo en el `POST` (envío del formulario), igual que estaba antes de sacarlo.
- No tocar Turnstile ni el `Cache::lock` de concurrencia (`HostedServerController::store()`) — siguen intactos, el throttle es una capa adicional, no un reemplazo.
- Entorno de test ya usa `CACHE_STORE=array` (ver `phpunit.xml`) — el `array` driver persiste durante la vida del proceso de test, así que un mismo método de test puede mandar múltiples `$this->post(...)` y el rate limiter cuenta correctamente entre ellos sin configuración extra.

---

### Task 1: Reactivar el throttle y agregar el test de regresión

**Files:**
- Modify: `routes/web.php:81-92` (grupo de rutas `hosted-servers.`)
- Create: `tests/Feature/HostedServerThrottleTest.php`

**Interfaces:**
- Consumes: ninguna — cambio de configuración de ruta, no toca código de aplicación.
- Produces: nada que otras tasks consuman — este plan tiene una sola task.

- [ ] **Step 1: Escribir el test que falla (throttle todavía desactivado)**

Crear `tests/Feature/HostedServerThrottleTest.php`:

```php
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
```

- [ ] **Step 2: Correr el test y confirmar que falla (throttle todavía no está activo)**

Run: `php artisan test --filter=HostedServerThrottleTest`

Expected: `test_throttles_after_20_attempts_in_60_seconds` FALLA — la request #21 devuelve `302` (la validación normal), no `429`, porque todavía no hay ningún límite de tasa en la ruta. `test_allows_requests_under_the_limit` PASA (no depende del throttle).

- [ ] **Step 3: Reactivar el throttle en la ruta**

En `routes/web.php`, reemplazar el bloque completo del grupo `hosted-servers.` (líneas 81-92) por:

```php
Route::prefix('servidores')->name('hosted-servers.')->group(function () {
    Route::get('/crear', [HostedServerController::class, 'create'])->name('create');
    // throttle:20,60 reactivado (2026-08-24) -- estuvo sacado desde el
    // 2026-08-22 (commit 4ebfd16) para que el dueño pudiera probar el flujo
    // sin pegarle al 429 durante el rediseño del form. Turnstile (ver
    // HostedServerController::passesTurnstile()) y el Cache::lock de
    // concurrencia global siguen siendo las otras dos capas -- esta es la
    // tercera, especifica contra rafagas rapidas desde una sola IP/sesion.
    // 20/60 (no el 3/60 original) para no bloquear a un usuario real que
    // reintenta el form varias veces por un error de validacion propio.
    Route::post('/crear', [HostedServerController::class, 'store'])->name('store')->middleware('throttle:20,60');
    Route::get('/{hostedServer}/{token}', [HostedServerController::class, 'show'])->name('show');
    Route::post('/{hostedServer}/{token}/detener', [HostedServerController::class, 'stop'])->name('stop');
});
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=HostedServerThrottleTest`

Expected: PASS — ambos tests. La request #21 en el primer test ahora devuelve `429`.

- [ ] **Step 5: Correr la suite completa para confirmar que no se rompió nada más**

Run: `php artisan test`

Expected: todos los tests existentes siguen en verde (este cambio no toca ninguna otra ruta ni comportamiento).

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/HostedServerThrottleTest.php
git commit -m "$(cat <<'EOF'
Reactivar throttle en POST /servidores/crear

Estuvo sacado desde el 2026-08-22 (commit 4ebfd16) para pruebas del
dueno durante el rediseño del form -- reactivado en 20/60 (no el
3/60 original) para no bloquear a un usuario real, con Turnstile y
el lock de concurrencia global como las otras dos capas de
proteccion. Ver CLAUDE.md, seccion "Mitigacion de abuso".
EOF
)"
```

---

## Notas para quien ejecute este plan

- Este cambio es puramente de código de aplicación (repo `cod2-ranking`) — no toca infraestructura del VPS. Se despliega con el mismo patrón que cualquier otro fix de esta migración: commit → push a GitHub → `git archive HEAD -- routes/web.php tests/Feature/HostedServerThrottleTest.php | ssh cod2-vps-new "tar -x -C /var/www/cod2.4livepro.com"` → `chown www-data:www-data` los archivos tocados → `php artisan view:clear` no hace falta (no es una vista), pero si el proyecto tuviera route cache activo habría que correr `php artisan route:clear` en el servidor (hoy no está cacheado, confirmado en la migración — no hay `bootstrap/cache/routes-v7.php`).
- Después de desplegar, verificar en producción con `curl` real (no solo el test): mandar 21 `POST` seguidos a `https://cod2.4livepro.com/servidores/crear` y confirmar que el request 21 da `429`, no re-testear con datos válidos que realmente creen servidores de prueba.
