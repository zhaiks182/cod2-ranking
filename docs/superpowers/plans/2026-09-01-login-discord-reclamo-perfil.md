# Login con Discord + reclamo de perfil + biografía Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Login público con Discord, reclamo de un perfil de jugador (`players`) por código de chat, y edición de biografía/redes sociales/specs de PC visibles en `/jugadores/{guid}`.

**Architecture:** Tabla y guard de autenticación (`site_users`/`site`) completamente separados del guard `web` que usa el panel admin — cero riesgo de mezclar cuentas públicas con el sistema de roles admin. El login usa Socialite (Discord OAuth2). El reclamo se confirma con un job programado que revisa `chat_messages` (ya poblada por el parser existente) buscando un código único que el jugador escribe en el chat del juego — sin aprobación manual.

**Tech Stack:** Laravel 13 / PHP 8.3, `laravel/socialite` + `socialiteproviders/discord` (nuevos), MySQL/MariaDB (SQLite en tests), Blade + Tailwind CDN (sin build step, mismo criterio que el resto del sitio).

**Spec:** [docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md](../specs/2026-09-01-login-discord-reclamo-perfil-design.md)

## Global Constraints

- Sin Markdown/HTML en la biografía — texto plano, máximo 400 caracteres.
- Cardinalidad 1:1 entre `site_users` y `players` (`site_users.player_id` único a nivel de esquema).
- El botón "Iniciar sesión con Discord" solo se muestra si `config('services.discord.client_id')` está cargado — mismo patrón que `TurnstileVerifier` (si no está configurado, la feature se oculta sin romper el resto del sitio).
- Todo commit sigue el estilo de mensajes ya usado en este repo (`feat:`, `fix:`, `test:`, `docs:`, en español, sin firma "Generated with Claude" salvo que el usuario pida commitear — en este plan los commits son parte del flujo de desarrollo, no de un `git commit` final al usuario).
- Todas las rutas nuevas van en `routes/web.php`, no se crea un archivo de rutas nuevo.
- Los admins gestionan las cuentas de Discord vinculadas bajo el módulo existente `players` (`User::MODULES`), sin crear un módulo nuevo.

---

## Task 1: Modelo `SiteUser` + relación con `Player`

**Files:**
- Create: `database/migrations/2026_09_01_100000_create_site_users_table.php`
- Create: `app/Models/SiteUser.php`
- Modify: `app/Models/Player.php`
- Test: `tests/Feature/Support/SiteUserPlayerRelationTest.php`

**Interfaces:**
- Produces: `SiteUser` (Eloquent model, `Authenticatable`) con columnas `discord_id`, `discord_username`, `discord_avatar_url`, `player_id`, `pending_claim_player_id`, `claim_code`, `claim_code_expires_at` (cast `datetime`), `bio`, `steam_url`, `twitch_url`, `instagram_url`, `pc_cpu`, `pc_gpu`, `pc_ram`, `pc_peripherals`. Métodos: `player(): BelongsTo`, `pendingClaimPlayer(): BelongsTo`, `hasPendingClaim(): bool`.
- Produces: `Player::siteUser(): HasOne`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Support;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteUserPlayerRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_site_user_can_be_linked_to_a_player_and_read_back_both_ways(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $siteUser = SiteUser::create([
            'discord_id' => '123456789012345678',
            'discord_username' => 'zhaiks',
            'player_id' => $player->id,
        ]);

        $this->assertTrue($player->fresh()->siteUser->is($siteUser));
        $this->assertTrue($siteUser->fresh()->player->is($player));
    }

    public function test_has_pending_claim_is_true_only_while_the_code_has_not_expired(): void
    {
        $player = Player::create(['guid' => 222, 'last_name' => 'Otro', 'last_name_plain' => 'Otro']);

        $pending = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id,
            'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);
        $expired = SiteUser::create([
            'discord_id' => '2', 'discord_username' => 'b',
            'pending_claim_player_id' => $player->id,
            'claim_code' => 'ZZZZZZZZ',
            'claim_code_expires_at' => now()->subMinute(),
        ]);
        $none = SiteUser::create(['discord_id' => '3', 'discord_username' => 'c']);

        $this->assertTrue($pending->hasPendingClaim());
        $this->assertFalse($expired->hasPendingClaim());
        $this->assertFalse($none->hasPendingClaim());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SiteUserPlayerRelationTest`
Expected: FAIL — clase `SiteUser` no existe / tabla `site_users` no existe.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuentas publicas del sitio (login con Discord, 2026-09-01) --
        // completamente separadas de `users` (solo panel admin). Ver
        // docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
        Schema::create('site_users', function (Blueprint $table) {
            $table->id();
            $table->string('discord_id')->unique();
            $table->string('discord_username');
            $table->string('discord_avatar_url')->nullable();

            // Reclamo confirmado -- unico (nullable, MySQL/MariaDB permiten
            // multiples NULL, mismo patron que hosted_servers.port) para que
            // un jugador solo pueda estar reclamado por una cuenta.
            $table->foreignId('player_id')->nullable()->unique()->constrained('players')->nullOnDelete();

            // Reclamo en curso, sin confirmar todavia.
            $table->foreignId('pending_claim_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('claim_code', 20)->nullable();
            $table->timestamp('claim_code_expires_at')->nullable();

            $table->string('bio', 400)->nullable();
            $table->string('steam_url')->nullable();
            $table->string('twitch_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('pc_cpu')->nullable();
            $table->string('pc_gpu')->nullable();
            $table->string('pc_ram')->nullable();
            $table->string('pc_peripherals')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_users');
    }
};
```

- [ ] **Step 4: Write the `SiteUser` model**

Create `app/Models/SiteUser.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Cuenta publica del sitio (login con Discord) -- guard `site`, separado del
 * guard `web` que usa el panel admin (tabla `users`). Ver "Autenticacion" en
 * docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
 */
class SiteUser extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'discord_id', 'discord_username', 'discord_avatar_url',
        'player_id', 'pending_claim_player_id', 'claim_code', 'claim_code_expires_at',
        'bio', 'steam_url', 'twitch_url', 'instagram_url',
        'pc_cpu', 'pc_gpu', 'pc_ram', 'pc_peripherals',
    ];

    protected $casts = [
        'claim_code_expires_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function pendingClaimPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'pending_claim_player_id');
    }

    public function hasPendingClaim(): bool
    {
        return $this->pending_claim_player_id !== null
            && $this->claim_code_expires_at !== null
            && $this->claim_code_expires_at->isFuture();
    }
}
```

- [ ] **Step 5: Add the inverse relation on `Player`**

In `app/Models/Player.php`, add the import and the relation method (junto a las demas relaciones `hasMany`):

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

```php
    public function siteUser(): HasOne
    {
        return $this->hasOne(SiteUser::class);
    }
```

- [ ] **Step 6: Run migrations and tests, verify pass**

Run: `php artisan migrate` (entorno de test usa SQLite en memoria vía `RefreshDatabase`, no hace falta correrlo a mano ahí, pero sí correrlo para que el resto de la app tenga la tabla localmente)
Run: `php artisan test --filter=SiteUserPlayerRelationTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_01_100000_create_site_users_table.php app/Models/SiteUser.php app/Models/Player.php tests/Feature/Support/SiteUserPlayerRelationTest.php
git commit -m "feat: agrega tabla site_users y relacion con Player"
```

---

## Task 2: Login con Discord (OAuth) — guard, config y controlador

**Files:**
- Modify: `composer.json` (vía `composer require`)
- Modify: `config/auth.php`
- Modify: `config/services.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `bootstrap/app.php`
- Modify: `.env.example`
- Create: `app/Http/Controllers/SiteAuthController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/Auth/DiscordLoginTest.php`

**Interfaces:**
- Consumes: `SiteUser` (Task 1) — `discord_id`, `discord_username`, `discord_avatar_url`.
- Produces: guard `site` usable como `Auth::guard('site')` / middleware `auth:site` en cualquier ruta futura. Rutas nombradas `login`, `auth.discord.callback`, `logout`. Helper de vista: `auth('site')->check()`, `auth('site')->user()`.

- [ ] **Step 1: Install Socialite + el driver de Discord**

```bash
composer require laravel/socialite socialiteproviders/discord
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Auth/DiscordLoginTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class DiscordLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDiscordUser(string $id = '111111111111111111', string $username = 'zhaiks'): SocialiteUser
    {
        return (new SocialiteUser())->setRaw([])->map([
            'id' => $id,
            'nickname' => $username,
            'name' => $username,
            'avatar' => "https://cdn.discordapp.com/avatars/{$id}/abc.png",
        ]);
    }

    public function test_callback_creates_a_new_site_user_and_logs_them_in(): void
    {
        Socialite::shouldReceive('driver->user')->andReturn($this->fakeDiscordUser());

        $response = $this->get('/auth/discord/callback');

        $siteUser = SiteUser::where('discord_id', '111111111111111111')->first();
        $this->assertNotNull($siteUser);
        $this->assertSame('zhaiks', $siteUser->discord_username);
        $this->assertAuthenticated('site');
        $response->assertRedirect(route('account.show'));
    }

    public function test_callback_updates_the_username_and_avatar_on_a_returning_user_without_duplicating_the_row(): void
    {
        SiteUser::create(['discord_id' => '111111111111111111', 'discord_username' => 'nombreviejo']);

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeDiscordUser(username: 'nombrenuevo'));

        $this->get('/auth/discord/callback');

        $this->assertSame(1, SiteUser::where('discord_id', '111111111111111111')->count());
        $this->assertSame('nombrenuevo', SiteUser::first()->discord_username);
    }

    public function test_guests_hitting_a_site_protected_route_are_sent_to_the_public_login_not_the_admin_one(): void
    {
        $response = $this->get('/mi-cuenta');

        $response->assertRedirect(route('login'));
    }

    public function test_logout_clears_the_site_session(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'x']);
        $this->actingAs($siteUser, 'site');

        $this->post('/logout');

        $this->assertGuest('site');
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=DiscordLoginTest`
Expected: FAIL — ruta `/mi-cuenta` no existe todavía, `/auth/discord/callback` no existe, guard `site` no configurado.

- [ ] **Step 4: Configurar el guard y el provider**

En `config/auth.php`, agregar el import junto al de `User` y extender `guards`/`providers`:

```php
use App\Models\SiteUser;
```

```php
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Cuentas publicas (login con Discord, 2026-09-01) -- completamente
        // separado del guard 'web' que usa el panel admin.
        'site' => [
            'driver' => 'session',
            'provider' => 'site_users',
        ],
    ],
```

```php
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'site_users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_SITE_MODEL', SiteUser::class),
        ],
    ],
```

- [ ] **Step 5: Agregar las credenciales de Discord OAuth a `config/services.php`**

La entrada `discord` ya existe (`guild_id`/`invite_url`) — agregarle las claves nuevas, sin crear una segunda entrada:

```php
    'discord' => [
        'guild_id' => env('DISCORD_GUILD_ID'),
        'invite_url' => env('DISCORD_INVITE_URL'),

        // OAuth "Iniciar sesión con Discord" (2026-09-01) -- credenciales de
        // developer.discord.com/applications, DISTINTAS del guild_id de
        // arriba (ese es publico, esto es secreto). El boton de login se
        // oculta si client_id falta -- mismo patron que TurnstileVerifier.
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI'),
    ],
```

- [ ] **Step 6: Registrar el driver de Discord en Socialite**

En `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;
```

Dentro de `boot()`, antes o después del `View::composer` existente:

```php
        // Registra el driver de Discord para Socialite (paquete
        // socialiteproviders/discord -- Discord no es un driver oficial).
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });
```

- [ ] **Step 7: Diferenciar el redirect de invitados por path**

En `bootstrap/app.php`, reemplazar la línea del `redirectGuestsTo` fijo:

```php
        $middleware->redirectGuestsTo('/adm_cod2/login');
```

por:

```php
        // Dos guards, dos pantallas de login: /adm_cod2/* (guard `web`, panel
        // admin) sigue yendo a /adm_cod2/login; cualquier otra ruta protegida
        // por auth:site (login publico con Discord) va a /login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('adm_cod2/*')
            ? route('admin.login')
            : route('login'));
```

- [ ] **Step 8: Crear el controlador**

Create `app/Http/Controllers/SiteAuthController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\SiteUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SiteAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('discord')->redirect();
    }

    public function callback()
    {
        $discordUser = Socialite::driver('discord')->user();

        $siteUser = SiteUser::updateOrCreate(
            ['discord_id' => $discordUser->getId()],
            [
                'discord_username' => $discordUser->getNickname() ?? $discordUser->getName(),
                'discord_avatar_url' => $discordUser->getAvatar(),
            ]
        );

        Auth::guard('site')->login($siteUser);

        return redirect()->intended(route('account.show'));
    }

    public function logout(Request $request)
    {
        Auth::guard('site')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
```

- [ ] **Step 9: Agregar las rutas**

En `routes/web.php`, agregar el `use` junto a los demás controllers públicos:

```php
use App\Http\Controllers\SiteAuthController;
```

Y las rutas, después del bloque de `/idioma/{locale}` (fuera del grupo `adm_cod2`, en el grupo público):

```php
// Login publico con Discord (2026-09-01) -- guard `site`, separado del login
// admin. Ver docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
Route::get('/login', [SiteAuthController::class, 'redirect'])->name('login');
Route::get('/auth/discord/callback', [SiteAuthController::class, 'callback'])->name('auth.discord.callback');
Route::post('/logout', [SiteAuthController::class, 'logout'])->name('logout')->middleware('auth:site');

// Mi cuenta -- placeholder de ruta, el controlador real se agrega en la Task 5.
// Se deja la ruta acá porque DiscordLoginTest ya necesita que exista para
// probar el redirect de invitados.
Route::get('/mi-cuenta', fn () => redirect()->route('dashboard'))->name('account.show')->middleware('auth:site');
```

- [ ] **Step 10: Agregar el botón al nav**

En `resources/views/layouts/app.blade.php`, insertar justo antes de `</nav>` (después del bloque `lang-dropdown`, mismo lugar donde cierra el selector de idioma):

```blade
                @if (config('services.discord.client_id'))
                    @auth('site')
                        <div class="relative">
                            <button type="button" data-account-toggle onclick="document.getElementById('account-dropdown').classList.toggle('hidden')"
                                class="flex items-center gap-1.5 text-slate-300 hover:text-gsaccent transition-colors normal-case tracking-normal">
                                @if(auth('site')->user()->discord_avatar_url)
                                    <img src="{{ auth('site')->user()->discord_avatar_url }}" alt="" class="w-5 h-5 rounded-full">
                                @endif
                                {{ auth('site')->user()->discord_username }}
                            </button>
                            <div id="account-dropdown" class="hidden absolute right-0 mt-2 w-44 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                                <a href="{{ route('account.show') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">{{ __('Mi cuenta') }}</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">{{ __('Cerrar sesión') }}</button>
                                </form>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="text-slate-300 hover:text-gsaccent transition-colors normal-case tracking-normal">{{ __('Iniciar sesión') }}</a>
                    @endauth
                @endif
```

Y en el listener de "click afuera cierra dropdowns" (más abajo en el mismo archivo, junto al de `searchDropdown`), agregar:

```javascript
            const accountDropdown = document.getElementById('account-dropdown');
            if (accountDropdown && !accountDropdown.contains(e.target) && !e.target.closest('[data-account-toggle]')) {
                accountDropdown.classList.add('hidden');
            }
```

- [ ] **Step 11: Agregar las variables de entorno de ejemplo**

En `.env.example`, después de `DISCORD_INVITE_URL=`:

```
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_REDIRECT_URI=
```

- [ ] **Step 12: Run tests to verify they pass**

Run: `php artisan test --filter=DiscordLoginTest`
Expected: PASS (4 tests)

- [ ] **Step 13: Commit**

```bash
git add composer.json composer.lock config/auth.php config/services.php app/Providers/AppServiceProvider.php bootstrap/app.php app/Http/Controllers/SiteAuthController.php routes/web.php resources/views/layouts/app.blade.php .env.example tests/Feature/Auth/DiscordLoginTest.php
git commit -m "feat: login publico con Discord (guard site, separado del admin)"
```

---

## Task 3: Reclamo de perfil (iniciar y cancelar)

**Files:**
- Create: `app/Http/Controllers/PlayerClaimController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PlayerClaimTest.php`

**Interfaces:**
- Consumes: `SiteUser` (Task 1) — `player_id`, `pending_claim_player_id`, `claim_code`, `claim_code_expires_at`.
- Produces: `PlayerClaimController::store(Player $player)`, `PlayerClaimController::cancel()`. Rutas `players.claim.store` (`POST /jugadores/{player:guid}/reclamar`), `account.claim.cancel` (`POST /mi-cuenta/reclamo/cancelar`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerClaimTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_claim_generates_a_code_valid_for_15_minutes(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')
            ->post(route('players.claim.store', $player))
            ->assertRedirect(route('account.show'));

        $siteUser->refresh();
        $this->assertSame($player->id, $siteUser->pending_claim_player_id);
        $this->assertNotNull($siteUser->claim_code);
        $this->assertTrue($siteUser->claim_code_expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
    }

    public function test_a_guest_cannot_start_a_claim(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $this->post(route('players.claim.store', $player))->assertRedirect(route('login'));
    }

    public function test_claiming_a_player_already_claimed_by_someone_else_is_rejected(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => 'owner', 'discord_username' => 'dueño', 'player_id' => $player->id]);
        $siteUser = SiteUser::create(['discord_id' => 'other', 'discord_username' => 'otro']);

        $this->actingAs($siteUser, 'site')->post(route('players.claim.store', $player));

        $this->assertNull($siteUser->fresh()->pending_claim_player_id);
    }

    public function test_a_site_user_who_already_claimed_a_player_cannot_start_a_second_claim(): void
    {
        $claimed = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $other = Player::create(['guid' => 222, 'last_name' => 'Otro', 'last_name_plain' => 'Otro']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $claimed->id]);

        $this->actingAs($siteUser, 'site')->post(route('players.claim.store', $other));

        $this->assertNull($siteUser->fresh()->pending_claim_player_id);
    }

    public function test_canceling_a_pending_claim_clears_it(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($siteUser, 'site')->post(route('account.claim.cancel'));

        $siteUser->refresh();
        $this->assertNull($siteUser->pending_claim_player_id);
        $this->assertNull($siteUser->claim_code);
        $this->assertNull($siteUser->claim_code_expires_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlayerClaimTest`
Expected: FAIL — clase `PlayerClaimController` y rutas no existen.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/PlayerClaimController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PlayerClaimController extends Controller
{
    public function store(Player $player)
    {
        $siteUser = Auth::guard('site')->user();

        if ($siteUser->player_id !== null) {
            return back()->with('error', __('Ya tenés un perfil reclamado. Contactá a un admin si es un error.'));
        }

        if (SiteUser::where('player_id', $player->id)->exists()) {
            return back()->with('error', __('Este perfil ya fue reclamado. Si es un error, contactá a un admin.'));
        }

        $siteUser->update([
            'pending_claim_player_id' => $player->id,
            'claim_code' => strtoupper(Str::random(8)),
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        return redirect()->route('account.show');
    }

    public function cancel()
    {
        Auth::guard('site')->user()->update([
            'pending_claim_player_id' => null,
            'claim_code' => null,
            'claim_code_expires_at' => null,
        ]);

        return redirect()->route('account.show');
    }
}
```

- [ ] **Step 4: Add the routes**

En `routes/web.php`, agregar el `use`:

```php
use App\Http\Controllers\PlayerClaimController;
```

Y las rutas, junto a las de `players.*` públicas (después de `Route::get('/jugadores/{player:guid}', ...)`):

```php
Route::post('/jugadores/{player:guid}/reclamar', [PlayerClaimController::class, 'store'])->name('players.claim.store')->middleware('auth:site');
```

Y junto a la ruta de `/mi-cuenta` agregada en la Task 2:

```php
Route::post('/mi-cuenta/reclamo/cancelar', [PlayerClaimController::class, 'cancel'])->name('account.claim.cancel')->middleware('auth:site');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=PlayerClaimTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerClaimController.php routes/web.php tests/Feature/PlayerClaimTest.php
git commit -m "feat: reclamo de perfil de jugador (iniciar y cancelar)"
```

---

## Task 4: Confirmación automática del reclamo (`players:check-claims`)

**Files:**
- Create: `app/Console/Commands/CheckPlayerClaims.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/CheckPlayerClaimsCommandTest.php`

**Interfaces:**
- Consumes: `SiteUser::hasPendingClaim()` (no se usa directamente acá, la query del comando reimplementa el filtro de "vigente" con `where`), `ChatMessage` (`guid`, `message`, `occurred_at`).
- Produces: comando `players:check-claims`, programado cada minuto.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CheckPlayerClaimsCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Player;
use App\Models\Server;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckPlayerClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function pendingClaim(int $guid, string $code, \Illuminate\Support\Carbon $expiresAt): SiteUser
    {
        $player = Player::create(['guid' => $guid, 'last_name' => "P{$guid}", 'last_name_plain' => "P{$guid}"]);

        return SiteUser::create([
            'discord_id' => (string) $guid, 'discord_username' => "user{$guid}",
            'pending_claim_player_id' => $player->id, 'claim_code' => $code,
            'claim_code_expires_at' => $expiresAt,
        ]);
    }

    public function test_confirms_a_claim_when_the_code_appears_in_chat_from_the_right_guid(): void
    {
        $siteUser = $this->pendingClaim(111, 'ABCDEFGH', now()->addMinutes(10));
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 111, 'name' => 'P111',
            'message' => 'mi codigo es ABCDEFGH', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims');

        $siteUser->refresh();
        $this->assertNotNull($siteUser->player_id);
        $this->assertNull($siteUser->pending_claim_player_id);
        $this->assertNull($siteUser->claim_code);
        $this->assertNull($siteUser->claim_code_expires_at);
    }

    public function test_does_not_confirm_when_the_code_appears_from_a_different_guid(): void
    {
        $siteUser = $this->pendingClaim(111, 'ABCDEFGH', now()->addMinutes(10));
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 999, 'name' => 'Otro',
            'message' => 'ABCDEFGH', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims');

        $this->assertNull($siteUser->fresh()->player_id);
    }

    public function test_does_not_confirm_an_expired_claim_even_if_the_code_appears(): void
    {
        $siteUser = $this->pendingClaim(111, 'ABCDEFGH', now()->subMinute());
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 111, 'name' => 'P111',
            'message' => 'ABCDEFGH', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims');

        $this->assertNull($siteUser->fresh()->player_id);
    }

    public function test_does_nothing_when_there_are_no_pending_claims(): void
    {
        $this->artisan('players:check-claims')->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CheckPlayerClaimsCommandTest`
Expected: FAIL — el comando `players:check-claims` no existe.

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/CheckPlayerClaims.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\SiteUser;
use Illuminate\Console\Command;

class CheckPlayerClaims extends Command
{
    protected $signature = 'players:check-claims';

    protected $description = 'Confirma reclamos de perfil pendientes cuyo codigo aparecio en el chat del juego';

    public function handle(): void
    {
        $pending = SiteUser::with('pendingClaimPlayer')
            ->whereNotNull('pending_claim_player_id')
            ->where('claim_code_expires_at', '>=', now())
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        // Mismo margen (20 min) que la expiracion del codigo (15 min) mas
        // holgura, y mismo criterio que DemoMatchResolver: una sola query
        // chica en vez de una por cada reclamo pendiente.
        $recentMessages = ChatMessage::where('occurred_at', '>=', now()->subMinutes(20))->get();

        foreach ($pending as $siteUser) {
            $targetGuid = $siteUser->pendingClaimPlayer->guid;

            $match = $recentMessages->first(
                fn ($m) => $m->guid === $targetGuid && str_contains($m->message, $siteUser->claim_code)
            );

            if ($match) {
                $siteUser->update([
                    'player_id' => $siteUser->pending_claim_player_id,
                    'pending_claim_player_id' => null,
                    'claim_code' => null,
                    'claim_code_expires_at' => null,
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Schedule the command**

En `routes/console.php`, agregar junto a los demás `Schedule::command(...)->everyMinute()`:

```php
Schedule::command('players:check-claims')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=CheckPlayerClaimsCommandTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CheckPlayerClaims.php routes/console.php tests/Feature/CheckPlayerClaimsCommandTest.php
git commit -m "feat: confirma reclamos de perfil por codigo de chat (players:check-claims)"
```

---

## Task 5: Página "Mi cuenta" (ver estado, editar bio/redes/specs)

**Files:**
- Create: `app/Http/Controllers/AccountController.php`
- Modify: `routes/web.php`
- Create: `resources/views/account/show.blade.php`
- Test: `tests/Feature/AccountControllerTest.php`

**Interfaces:**
- Consumes: `SiteUser` (Task 1), `PlayerClaimController` routes (Task 3, para los botones de cancelar/reclamar desde esta vista).
- Produces: `AccountController::show()`, `AccountController::update(Request $request)`. Rutas `account.show` (reemplaza el placeholder de la Task 2), `account.update`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AccountControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('account.show'))->assertRedirect(route('login'));
    }

    public function test_shows_the_pending_claim_code_when_there_is_one(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($siteUser, 'site')->get(route('account.show'))
            ->assertOk()
            ->assertSee('ABCDEFGH');
    }

    public function test_a_claimed_user_can_update_their_bio_and_socials(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')->post(route('account.update'), [
            'bio' => 'Jugador de CoD2 desde 2003.',
            'steam_url' => 'https://steamcommunity.com/id/zhaiks',
            'pc_cpu' => 'Ryzen 5600X',
        ])->assertRedirect();

        $siteUser->refresh();
        $this->assertSame('Jugador de CoD2 desde 2003.', $siteUser->bio);
        $this->assertSame('https://steamcommunity.com/id/zhaiks', $siteUser->steam_url);
        $this->assertSame('Ryzen 5600X', $siteUser->pc_cpu);
    }

    public function test_an_unclaimed_user_cannot_update_the_profile_fields(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['bio' => 'intento'])
            ->assertForbidden();

        $this->assertNull($siteUser->fresh()->bio);
    }

    public function test_the_bio_cannot_exceed_400_characters(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['bio' => str_repeat('x', 401)])
            ->assertSessionHasErrors('bio');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AccountControllerTest`
Expected: FAIL — `AccountController::update` no existe, `account.update` no está ruteada.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/AccountController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function show()
    {
        $siteUser = Auth::guard('site')->user()->load('player', 'pendingClaimPlayer');

        return view('account.show', compact('siteUser'));
    }

    public function update(Request $request)
    {
        $siteUser = Auth::guard('site')->user();

        abort_unless($siteUser->player_id !== null, 403);

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:400'],
            'steam_url' => ['nullable', 'string', 'max:255'],
            'twitch_url' => ['nullable', 'string', 'max:255'],
            'instagram_url' => ['nullable', 'string', 'max:255'],
            'pc_cpu' => ['nullable', 'string', 'max:120'],
            'pc_gpu' => ['nullable', 'string', 'max:120'],
            'pc_ram' => ['nullable', 'string', 'max:120'],
            'pc_peripherals' => ['nullable', 'string', 'max:120'],
        ]);

        $siteUser->update($data);

        return back()->with('status', __('Perfil actualizado.'));
    }
}
```

- [ ] **Step 4: Replace the placeholder route and add `account.update`**

En `routes/web.php`, reemplazar el placeholder de la Task 2:

```php
Route::get('/mi-cuenta', fn () => redirect()->route('dashboard'))->name('account.show')->middleware('auth:site');
```

por:

```php
Route::get('/mi-cuenta', [AccountController::class, 'show'])->name('account.show')->middleware('auth:site');
Route::post('/mi-cuenta', [AccountController::class, 'update'])->name('account.update')->middleware('auth:site');
```

Y agregar el `use` correspondiente:

```php
use App\Http\Controllers\AccountController;
```

- [ ] **Step 5: Write the view**

Create `resources/views/account/show.blade.php`:

```blade
@extends('layouts.app')

@section('title', __('Mi cuenta'))

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-xl font-semibold">{{ __('Mi cuenta') }}</h1>

    @if(session('status'))
        <div class="rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300 text-sm px-4 py-2">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-red-800 bg-red-950/40 text-red-300 text-sm px-4 py-2">{{ session('error') }}</div>
    @endif

    @if($siteUser->player)
        {{-- Reclamado: form de edicion --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4">
            <p class="text-sm text-slate-400 mb-3">
                {{ __('Perfil reclamado:') }}
                <a href="{{ route('players.show', $siteUser->player) }}" class="text-cyan-400 hover:underline">{{ $siteUser->player->last_name_plain }}</a>
            </p>
            <form method="POST" action="{{ route('account.update') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Biografía') }}</label>
                    <textarea name="bio" maxlength="400" rows="3" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">{{ old('bio', $siteUser->bio) }}</textarea>
                    @error('bio')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">Steam</label>
                        <input type="text" name="steam_url" value="{{ old('steam_url', $siteUser->steam_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">Twitch</label>
                        <input type="text" name="twitch_url" value="{{ old('twitch_url', $siteUser->twitch_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">Instagram</label>
                        <input type="text" name="instagram_url" value="{{ old('instagram_url', $siteUser->instagram_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">CPU</label>
                        <input type="text" name="pc_cpu" value="{{ old('pc_cpu', $siteUser->pc_cpu) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">GPU</label>
                        <input type="text" name="pc_gpu" value="{{ old('pc_gpu', $siteUser->pc_gpu) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">RAM</label>
                        <input type="text" name="pc_ram" value="{{ old('pc_ram', $siteUser->pc_ram) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Periféricos') }}</label>
                        <input type="text" name="pc_peripherals" value="{{ old('pc_peripherals', $siteUser->pc_peripherals) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-gsprimary text-white text-sm font-semibold hover:bg-gsprimary/80">{{ __('Guardar') }}</button>
            </form>
        </div>
    @elseif($siteUser->hasPendingClaim())
        {{-- Reclamo pendiente: mostrar el codigo --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
            <p class="text-sm text-slate-300">
                {{ __('Escribí este código en el chat del servidor dentro de los próximos 15 minutos para confirmar que sos') }}
                <strong>{{ $siteUser->pendingClaimPlayer->last_name_plain }}</strong>:
            </p>
            <p class="text-2xl font-mono font-semibold text-cyan-400">{{ $siteUser->claim_code }}</p>
            <p class="text-xs text-slate-500">{{ __('Vence') }}: {{ $siteUser->claim_code_expires_at->format('d/m/Y H:i') }}</p>
            <form method="POST" action="{{ route('account.claim.cancel') }}">
                @csrf
                <button type="submit" class="text-xs text-red-400 hover:underline">{{ __('Cancelar reclamo') }}</button>
            </form>
        </div>
    @elseif($siteUser->pending_claim_player_id)
        {{-- Codigo vencido sin confirmar --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
            <p class="text-sm text-slate-400">{{ __('El código venció sin confirmarse. Volvé al perfil del jugador para generar uno nuevo.') }}</p>
            <a href="{{ route('players.show', $siteUser->pendingClaimPlayer) }}" class="text-cyan-400 hover:underline text-sm">{{ $siteUser->pendingClaimPlayer->last_name_plain }}</a>
        </div>
    @else
        {{-- Sin ningun reclamo todavia --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4">
            <p class="text-sm text-slate-400">{{ __('Todavía no reclamaste ningún perfil. Buscá tu nombre en el sitio y tocá "¿Sos vos?" en tu página de jugador.') }}</p>
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=AccountControllerTest`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AccountController.php routes/web.php resources/views/account/show.blade.php tests/Feature/AccountControllerTest.php
git commit -m "feat: pagina Mi cuenta (estado de reclamo, edicion de bio/redes/specs)"
```

---

## Task 6: Mostrar bio/redes/specs y el botón de reclamo en `/jugadores/{guid}`

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php`
- Modify: `resources/views/players/show.blade.php`
- Test: `tests/Feature/PlayerProfileClaimDisplayTest.php`

**Interfaces:**
- Consumes: `Player::siteUser` (Task 1), `SiteUser` campos de perfil (Task 1), ruta `players.claim.store` (Task 3).
- Produces: variable de vista `$canClaim` (bool) pasada por `PlayerController::show()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerProfileClaimDisplayTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerProfileClaimDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_bio_and_socials_when_the_player_is_claimed(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id,
            'bio' => 'Jugador desde 2003.', 'steam_url' => 'https://steamcommunity.com/id/zhaiks',
        ]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Jugador desde 2003.')
            ->assertSee('https://steamcommunity.com/id/zhaiks', false);
    }

    public function test_shows_the_claim_button_to_a_logged_in_visitor_without_a_claim_of_their_own(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->get(route('players.show', $player))
            ->assertOk()
            ->assertSee(__('¿Sos vos? Reclamá este perfil'));
    }

    public function test_hides_the_claim_button_from_an_anonymous_visitor(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee(__('¿Sos vos? Reclamá este perfil'));
    }

    public function test_hides_the_claim_button_when_the_player_is_already_claimed_by_someone_else(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => 'owner', 'discord_username' => 'dueño', 'player_id' => $player->id]);
        $viewer = SiteUser::create(['discord_id' => 'viewer', 'discord_username' => 'visitante']);

        $this->actingAs($viewer, 'site')->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee(__('¿Sos vos? Reclamá este perfil'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlayerProfileClaimDisplayTest`
Expected: FAIL — no se muestra bio ni el botón todavía.

- [ ] **Step 3: Update the controller**

En `app/Http/Controllers/PlayerController.php`, modificar la línea de `load()` y agregar `$canClaim` antes del `return view(...)`:

```php
        $player->load(['aliases' => fn ($q) => $q->orderByDesc('last_seen_at'), 'siteUser']);
```

```php
        $viewer = auth('site')->user();
        $canClaim = $viewer !== null && $player->siteUser === null && $viewer->player_id === null;
```

Y agregar `'canClaim'` al `compact(...)` del `return view(...)` final.

- [ ] **Step 4: Update the view**

En `resources/views/players/show.blade.php`, insertar el botón de reclamo dentro del bloque del guid (después de la línea del `<div class="text-xs font-mono text-cyan-400...">Guid: ...</div>`, antes de que cierre el `<div>` que lo contiene):

```blade
            @if($canClaim)
                <form method="POST" action="{{ route('players.claim.store', $player) }}" class="mt-1">
                    @csrf
                    <button type="submit" class="text-xs text-cyan-400 hover:text-cyan-300 hover:underline">{{ __('¿Sos vos? Reclamá este perfil') }}</button>
                </form>
            @endif
```

Y agregar la tarjeta de bio/redes/specs justo después de que cierra el `<div>` exterior del header (el que contiene el `h1` + guid + selector de temporada) y antes de que abra la grilla de stats (`<div class="grid grid-cols-2 md:grid-cols-7 gap-3">`):

```blade
    @if($player->siteUser)
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
            @if($player->siteUser->bio)
                <p class="text-sm text-slate-300">{{ $player->siteUser->bio }}</p>
            @endif
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
                <span>Discord: {{ $player->siteUser->discord_username }}</span>
                @if($player->siteUser->steam_url)
                    <a href="{{ $player->siteUser->steam_url }}" target="_blank" rel="noopener" class="hover:text-gsaccent">Steam</a>
                @endif
                @if($player->siteUser->twitch_url)
                    <a href="{{ $player->siteUser->twitch_url }}" target="_blank" rel="noopener" class="hover:text-gsaccent">Twitch</a>
                @endif
                @if($player->siteUser->instagram_url)
                    <a href="{{ $player->siteUser->instagram_url }}" target="_blank" rel="noopener" class="hover:text-gsaccent">Instagram</a>
                @endif
            </div>
            @if($player->siteUser->pc_cpu || $player->siteUser->pc_gpu || $player->siteUser->pc_ram || $player->siteUser->pc_peripherals)
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    @if($player->siteUser->pc_cpu)<span>CPU: {{ $player->siteUser->pc_cpu }}</span>@endif
                    @if($player->siteUser->pc_gpu)<span>GPU: {{ $player->siteUser->pc_gpu }}</span>@endif
                    @if($player->siteUser->pc_ram)<span>RAM: {{ $player->siteUser->pc_ram }}</span>@endif
                    @if($player->siteUser->pc_peripherals)<span>{{ __('Periféricos') }}: {{ $player->siteUser->pc_peripherals }}</span>@endif
                </div>
            @endif
        </div>
    @endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=PlayerProfileClaimDisplayTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerController.php resources/views/players/show.blade.php tests/Feature/PlayerProfileClaimDisplayTest.php
git commit -m "feat: muestra bio/redes/specs y boton de reclamo en el perfil de jugador"
```

---

## Task 7: `PlayerMerger` traslada el vínculo de Discord al fusionar jugadores

**Files:**
- Modify: `app/Support/PlayerMerger.php`
- Modify: `tests/Feature/Support/PlayerMergerTest.php`

**Interfaces:**
- Consumes: tabla `site_users` (Task 1) directamente vía query builder, mismo estilo que el resto de `PlayerMerger`.

- [ ] **Step 1: Write the failing test**

Agregar a `tests/Feature/Support/PlayerMergerTest.php` (mismo archivo existente, agregar este método a la clase):

```php
    public function test_merging_moves_a_claimed_discord_account_link_to_the_target(): void
    {
        $source = Player::create(['guid' => 111, 'last_name' => 'Viejo', 'last_name_plain' => 'Viejo']);
        $target = Player::create(['guid' => 222, 'last_name' => 'Nuevo', 'last_name_plain' => 'Nuevo']);
        $siteUser = \App\Models\SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $source->id]);

        PlayerMerger::merge([$source->id], $target->id);

        $this->assertSame($target->id, $siteUser->fresh()->player_id);
    }

    public function test_merging_does_not_overwrite_a_discord_link_the_target_already_has(): void
    {
        $source = Player::create(['guid' => 111, 'last_name' => 'Viejo', 'last_name_plain' => 'Viejo']);
        $target = Player::create(['guid' => 222, 'last_name' => 'Nuevo', 'last_name_plain' => 'Nuevo']);
        $sourceSiteUser = \App\Models\SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $source->id]);
        $targetSiteUser = \App\Models\SiteUser::create(['discord_id' => '2', 'discord_username' => 'b', 'player_id' => $target->id]);

        PlayerMerger::merge([$source->id], $target->id);

        $this->assertSame($target->id, $targetSiteUser->fresh()->player_id);
        $this->assertNull($sourceSiteUser->fresh()->player_id);
    }
```

(Nota: `test_merging_does_not_overwrite_a_discord_link_the_target_already_has` deja al `$sourceSiteUser` con `player_id = null` después de fusionar porque su `player_id` original —el jugador fuente— ya no existe, no porque el código lo limpie explícitamente: comprobalo vía el `nullOnDelete` de la FK al borrarse `$source`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlayerMergerTest`
Expected: FAIL en los 2 casos nuevos — `site_users.player_id` sigue apuntando al jugador fuente ya borrado (o revierte a `null` por el `nullOnDelete`, nunca pasa al destino).

- [ ] **Step 3: Update `PlayerMerger::merge()`**

En `app/Support/PlayerMerger.php`, agregar esta línea dentro del `foreach ($sources as $source)`, justo después de la línea de `chat_messages`:

```php
                DB::table('chat_messages')->where('player_id', $source->id)->update(['player_id' => $target->id]);

                // Vinculo de cuenta de Discord (2026-09-01, ver
                // docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md)
                // -- si el jugador fuente tenia un reclamo confirmado, tiene que
                // seguir al destino: la fila fuente se borra al final de este
                // metodo, y el nullOnDelete de la FK NO alcanza aca (perderia el
                // reclamo en silencio en vez de trasladarlo). Defensivo: nunca pisa
                // un site_user que el destino ya tuviera (no deberia poder pasar
                // dado el 1:1, pero se verifica igual).
                if (! DB::table('site_users')->where('player_id', $target->id)->exists()) {
                    DB::table('site_users')->where('player_id', $source->id)->update(['player_id' => $target->id]);
                }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PlayerMergerTest`
Expected: PASS (todos los casos, incluidos los 2 nuevos)

- [ ] **Step 5: Commit**

```bash
git add app/Support/PlayerMerger.php tests/Feature/Support/PlayerMergerTest.php
git commit -m "fix: PlayerMerger traslada el vinculo de cuenta de Discord al fusionar jugadores"
```

---

## Task 8: Admin — ver y desvincular cuentas de Discord

**Files:**
- Create: `app/Http/Controllers/Admin/SiteUserController.php`
- Modify: `routes/web.php`
- Create: `resources/views/admin/players/discord-accounts.blade.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Test: `tests/Feature/Admin/SiteUserControllerTest.php`

**Interfaces:**
- Consumes: `SiteUser` (Task 1), `AdminAction::record()`, middleware `module:players`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/SiteUserControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\Player;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get(route('admin.players.discord-accounts.index'))->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_without_the_players_module_gets_a_403(): void
    {
        $admin = User::factory()->create(['is_super_admin' => false, 'permissions' => []]);

        $this->actingAs($admin)->get(route('admin.players.discord-accounts.index'))->assertForbidden();
    }

    public function test_index_lists_linked_accounts(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->actingAs($admin)->get(route('admin.players.discord-accounts.index'))
            ->assertOk()
            ->assertSee('zhaiks')
            ->assertSee('Zhaiks');
    }

    public function test_unlink_clears_the_player_id_and_audits(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->actingAs($admin)->delete(route('admin.players.discord-accounts.unlink', $siteUser))->assertRedirect();

        $this->assertNull($siteUser->fresh()->player_id);
        $this->assertDatabaseHas('admin_actions', ['action' => 'site-users.unlink']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SiteUserControllerTest`
Expected: FAIL — controlador y rutas no existen.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/SiteUserController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\SiteUser;

class SiteUserController extends Controller
{
    public function index()
    {
        $siteUsers = SiteUser::with('player')->orderByDesc('created_at')->get();

        return view('admin.players.discord-accounts', compact('siteUsers'));
    }

    public function unlink(SiteUser $siteUser)
    {
        $label = "{$siteUser->discord_username} (discord_id {$siteUser->discord_id})";
        $playerLabel = $siteUser->player?->last_name_plain ?? 'ninguno';

        $siteUser->update(['player_id' => null]);

        AdminAction::record('site-users.unlink', "Desvinculó la cuenta de Discord \"{$label}\" del jugador \"{$playerLabel}\"");

        return back()->with('status', 'Cuenta desvinculada.');
    }
}
```

- [ ] **Step 4: Add the routes**

En `routes/web.php`, agregar el `use`:

```php
use App\Http\Controllers\Admin\SiteUserController;
```

Y dentro del grupo `Route::middleware('module:players')->group(function () { ... })` existente (junto a las rutas de `players.icons.*`):

```php
            Route::get('/jugadores/cuentas-discord', [SiteUserController::class, 'index'])->name('players.discord-accounts.index');
            Route::delete('/jugadores/cuentas-discord/{siteUser}', [SiteUserController::class, 'unlink'])->name('players.discord-accounts.unlink');
```

- [ ] **Step 5: Write the view**

Create `resources/views/admin/players/discord-accounts.blade.php`:

```blade
@extends('layouts.admin')

@section('title', 'Cuentas de Discord')

@section('content')
<div class="space-y-4">
    <h1 class="text-xl font-semibold">Cuentas de Discord</h1>

    @if(session('status'))
        <div class="rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300 text-sm px-4 py-2">{{ session('status') }}</div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-panel">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2">Discord</th>
                    <th class="px-4 py-2">Jugador vinculado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($siteUsers as $siteUser)
                    <tr class="border-b border-slate-800/60">
                        <td class="px-4 py-2">{{ $siteUser->discord_username }}</td>
                        <td class="px-4 py-2">
                            @if($siteUser->player)
                                <a href="{{ route('players.show', $siteUser->player) }}" class="text-cyan-400 hover:underline">{{ $siteUser->player->last_name_plain }}</a>
                            @else
                                <span class="text-slate-500">Sin reclamar</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if($siteUser->player)
                                <form method="POST" action="{{ route('admin.players.discord-accounts.unlink', $siteUser) }}" onsubmit="return confirm('¿Desvincular esta cuenta del jugador?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:underline">Desvincular</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Todavía no hay ninguna cuenta de Discord registrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Add the nav link**

En `resources/views/layouts/admin.blade.php`, dentro del bloque `@if(auth()->user()->hasModule('players'))` de la dropdown "Moderación" (junto a "Fusionar jugadores"/"Borrar jugadores"/"Íconos de jugadores"):

```blade
                                    <a href="{{ route('admin.players.discord-accounts.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Cuentas de Discord</a>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=SiteUserControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/SiteUserController.php routes/web.php resources/views/admin/players/discord-accounts.blade.php resources/views/layouts/admin.blade.php tests/Feature/Admin/SiteUserControllerTest.php
git commit -m "feat: admin puede ver y desvincular cuentas de Discord (modulo players)"
```

---

## Task 9: Suite completa y actualización de `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:**
- Ninguna — task de cierre, documentación y verificación final.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: todos los tests nuevos en verde; sin regresiones sobre los fallos preexistentes ya conocidos del proyecto (`ExampleTest`, `CountriesSeasonTest` — dependen de la base GeoIP real no versionada, ver `CLAUDE.md`).

- [ ] **Step 2: Documentar en `CLAUDE.md`**

Agregar una sección nueva a `CLAUDE.md` (después de la sección "Webhook de Discord con resultado de partida", mismo estilo del resto del archivo) resumiendo: qué se construyó, la decisión de guard separado (`site_users`/`site`), el flujo de reclamo por código de chat, los campos de perfil (bio/redes/specs), la integración con `PlayerMerger`, y el pendiente de credenciales de Discord OAuth que el dueño tiene que cargar en el VPS (`DISCORD_CLIENT_ID`/`DISCORD_CLIENT_SECRET`/`DISCORD_REDIRECT_URI`) antes de que el botón de login aparezca en producción. Mencionar explícitamente que el bot de mover canales de voz (sub-proyecto 2) queda pendiente, ahora con `site_users.discord_id` ya disponible para usarlo.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: login con Discord, reclamo de perfil y biografia"
```

---

## Self-Review Notes

- **Cobertura del spec:** autenticación (Task 2), reclamo por código de chat (Tasks 3-4), datos de perfil bio/redes/specs (Tasks 5-6), admin (Task 8), integración con `PlayerMerger` (Task 7) — los 6 componentes de la sección "Arquitectura" del spec tienen tarea propia.
- **Pendiente fuera de este plan, documentado en el spec:** credenciales reales de Discord Developer Portal (acción manual del dueño en el VPS, no un paso de código) y el bot de mover canales de voz (sub-proyecto 2, spec propio a futuro).
- **Consistencia de tipos:** `SiteUser::player_id`/`pending_claim_player_id` como `foreignId` a `players.id` en todas las tareas; `Player::siteUser()` siempre `hasOne` (nunca `hasMany`, coherente con el unique de esquema); `players.guid` e `chat_messages.guid` ambos `integer`, comparados directo sin cast en el comando de la Task 4.
