# Temporadas — Infraestructura base (Sub-proyecto 1 de 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar de alta el concepto de "temporada" en cod2-ranking — un modelo `Season`, un control de admin para cerrar la temporada activa e iniciar una nueva, y `matches.season_id` asignado a cada partida en el momento en que se crea. Sin ningún cambio visible en el sitio público todavía (eso es sub-proyecto 2/3).

**Architecture:** Una tabla `seasons` (siempre exactamente una fila con `ended_at IS NULL` = la activa) y una columna `matches.season_id` (FK, nullable a nivel de esquema pero siempre poblada por código de aplicación) asignada una sola vez al crear la partida (`ParseCod2Log::openRound()`). Todo el historial existente se backfillea a una fila "Temporada 1" en la misma migración que crea la tabla. Un controller de admin nuevo (`Admin\SeasonController`) expone cerrar/abrir temporadas, auditado con el mecanismo `AdminAction` ya existente.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + Tailwind (CDN), MySQL en producción / SQLite en memoria para tests (ver `phpunit.xml`).

**Spec:** [docs/superpowers/specs/2026-08-25-temporadas-infraestructura-base-design.md](../specs/2026-08-25-temporadas-infraestructura-base-design.md)

## Global Constraints

- Una sola temporada activa **global** (no por servidor).
- El corte de temporada es **100% manual** — sin cron, sin fecha de corte automática.
- `season_id` se asigna **una única vez**, en el momento de creación de la partida — nunca se reasigna retroactivamente.
- `rounds` y `kills` no llevan su propia columna `season_id` — se consultan vía join contra `matches.season_id` cuando haga falta (sub-proyectos futuros).
- `matches.season_id` queda **nullable a nivel de esquema** (no `NOT NULL` en la base) — ajuste sobre la spec original al planificar: forzar `NOT NULL` con `->change()` requiere `doctrine/dbal` (no instalado en este proyecto) y, en SQLite (motor de los tests, ver `phpunit.xml`), tampoco es directo sin reconstruir la tabla. La garantía de "siempre tiene season_id" queda a nivel de aplicación — el único lugar que crea una `GameMatch` es `ParseCod2Log::openRound()` (Task 2), que siempre lo setea — y cubierta por los tests de esa tarea, no por una constraint de base de datos.
- Nombre de temporada: texto libre, escrito por el admin, requerido.
- Sin advertencia especial si hay una partida en curso al cerrar una temporada — el comportamiento natural (la partida en curso queda completa en la temporada vieja) es el esperado.

---

## Nota sobre testing de la migración de backfill

`RefreshDatabase` corre TODAS las migraciones sobre una base vacía antes de que
cualquier test inserte datos — así que la rama de la migración que backfillea
partidas *ya existentes* nunca se puede ejercitar con datos reales dentro de la
suite de PHPUnit (no hay ninguna partida todavía cuando la migración corre en un
test). Esto es consistente con cómo ya se verificaron otras migraciones de backfill
en este proyecto (ver `CLAUDE.md`, varias entradas de la bitácora) — se prueba por
inspección + se verifica a mano contra la base real de producción antes/después del
deploy, no con PHPUnit. El Task 1 de abajo incluye ese paso de verificación manual
como parte del trabajo, no lo deja implícito.

---

### Task 1: Tabla `seasons`, columna `matches.season_id`, modelo `Season`

**Files:**
- Create: `database/migrations/2026_08_25_180000_create_seasons_table_and_backfill_matches.php`
- Create: `app/Models/Season.php`
- Modify: `app/Models/GameMatch.php` (agregar `season_id` a `$fillable`, agregar relación `season()`)
- Test: `tests/Feature/SeasonModelTest.php`

**Interfaces:**
- Produces: `Season::current(): Season` (estático — la fila con `ended_at IS NULL`), `Season::matches(): HasMany`, `GameMatch::season(): BelongsTo`. `matches.season_id` (columna, `unsignedBigInteger`, nullable a nivel de esquema, FK a `seasons.id` — ver la nota en "Global Constraints" sobre por qué no es `NOT NULL` en la base).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_temporada_1_as_the_active_season(): void
    {
        $season = Season::current();

        $this->assertSame('Temporada 1', $season->name);
        $this->assertNull($season->ended_at);
    }

    public function test_current_returns_the_season_with_null_ended_at(): void
    {
        $old = Season::current();
        $old->update(['ended_at' => now()]);

        $new = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $this->assertTrue(Season::current()->is($new));
    }

    public function test_game_match_belongs_to_a_season(): void
    {
        $season = Season::current();

        $match = GameMatch::create([
            'server_id' => 1,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'season_id' => $season->id,
        ]);

        $this->assertTrue($match->season->is($season));
        $this->assertTrue($season->matches->contains($match));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/SeasonModelTest.php`
Expected: FAIL — `Class "App\Models\Season" not found` (o similar, la tabla/modelo todavía no existen).

- [ ] **Step 3: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Temporada 1" agrupa TODO el historial existente -- started_at es la fecha de
 * la partida mas vieja si hay alguna, o ahora si la base esta vacia (instalacion
 * nueva). matches.season_id se asigna una sola vez, al crear cada partida
 * (ParseCod2Log::openRound()) -- nunca se reasigna retroactivamente, por eso una
 * partida en curso cuando se cierra una temporada queda entera en la vieja sin
 * logica especial. Ver docs/superpowers/specs/2026-08-25-temporadas-infraestructura-base-design.md.
 *
 * season_id queda NULLABLE a nivel de esquema (no NOT NULL) -- forzar esa
 * constraint sobre una columna ya creada requiere Blueprint::change(), que a su
 * vez requiere doctrine/dbal (no instalado en este proyecto) y no es directo en
 * SQLite (el motor de los tests, ver phpunit.xml) sin reconstruir la tabla. La
 * garantia real de "toda partida tiene season_id" la da el codigo de aplicacion
 * (el unico lugar que crea un GameMatch es ParseCod2Log::openRound(), que
 * siempre lo setea), no la base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        $earliestMatchStartedAt = DB::table('matches')->min('started_at');

        $seasonId = DB::table('seasons')->insertGetId([
            'name' => 'Temporada 1',
            'started_at' => $earliestMatchStartedAt ?? now(),
            'ended_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedBigInteger('season_id')->nullable()->after('server_id');
            $table->foreign('season_id')->references('id')->on('seasons');
        });

        DB::table('matches')->update(['season_id' => $seasonId]);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['season_id']);
            $table->dropColumn('season_id');
        });

        Schema::dropIfExists('seasons');
    }
};
```

- [ ] **Step 4: Escribir el modelo `Season`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = ['name', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * La temporada activa -- exactamente una fila con ended_at NULL en todo
     * momento, garantizado por Admin\SeasonController@store (cierra la vieja y
     * crea la nueva dentro de la misma transaccion, nunca hay una ventana sin
     * ninguna activa).
     */
    public static function current(): self
    {
        return static::whereNull('ended_at')->firstOrFail();
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }
}
```

- [ ] **Step 5: Modificar `GameMatch`**

En `app/Models/GameMatch.php`, cambiar:

```php
    protected $fillable = ['server_id', 'map', 'gametype', 'started_at', 'ended_at'];
```

por:

```php
    protected $fillable = ['server_id', 'season_id', 'map', 'gametype', 'started_at', 'ended_at'];
```

Y agregar, junto a las otras relaciones (después de `server()`):

```php
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
```

(`BelongsTo` ya está importado en ese archivo.)

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/SeasonModelTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Correr toda la suite para descartar regresiones**

Run: `vendor/bin/phpunit`
Expected: mismo resultado que el baseline (solo el fallo preexistente y conocido de `tests/Unit/ExampleTest.php` o `tests/Feature/ExampleTest.php`, sin relación — ver `CLAUDE.md`).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_25_180000_create_seasons_table_and_backfill_matches.php app/Models/Season.php app/Models/GameMatch.php tests/Feature/SeasonModelTest.php
git commit -m "Agregar tabla seasons y matches.season_id, con Temporada 1 backfilleada"
```

- [ ] **Step 9 (verificación manual, no automatizable — ver la nota de arriba): correr esta migración contra una COPIA descartable de la base de datos real de producción antes del deploy final**

En un clon descartable en el VPS (mismo patrón que el resto de esta sesión —
`git archive HEAD | ssh ... tar -x`, `composer install`, pero esta vez apuntando
`.env`/`DB_*` a una base de datos MySQL restaurada de un dump reciente de
producción, NO a SQLite en memoria):

```bash
php artisan migrate --force
```

Verificar a mano (`php artisan tinker`):

```php
App\Models\Season::count(); // debe ser 1
App\Models\Season::current()->name; // "Temporada 1"
App\Models\GameMatch::whereNull('season_id')->count(); // debe ser 0
App\Models\GameMatch::count(); // debe coincidir con el conteo de antes de migrar
```

Recién con esto verificado, la migración real se corre contra producción como parte del deploy normal (`php artisan migrate --force`, mismo flujo que el resto de esta sesión).

---

### Task 2: Asignar `season_id` a cada partida nueva en `ParseCod2Log`

**Files:**
- Modify: `app/Console/Commands/ParseCod2Log.php:266` (dentro de `openRound()`)
- Test: `tests/Feature/ParseCod2LogSeasonTest.php`

**Interfaces:**
- Consumes: `Season::current(): Season` (Task 1).
- Produces: cada `GameMatch` creado por el parser tiene `season_id` de la temporada activa en ese momento.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseCod2LogSeasonTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(string $logPath): Server
    {
        return Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => $logPath,
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);
    }

    private function realMatchLog(): string
    {
        return implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '',
        ]);
    }

    public function test_a_new_match_gets_the_currently_active_season(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $this->realMatchLog());
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(1, GameMatch::count());
        $this->assertSame(Season::current()->id, GameMatch::first()->season_id);

        @unlink($logPath);
    }

    public function test_a_match_created_after_closing_a_season_gets_the_new_one(): void
    {
        $oldSeason = Season::current();
        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $this->realMatchLog());
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(1, GameMatch::count());
        $this->assertSame($newSeason->id, GameMatch::first()->season_id);
        $this->assertNotSame($oldSeason->id, GameMatch::first()->season_id);

        @unlink($logPath);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/ParseCod2LogSeasonTest.php`
Expected: FAIL — `openRound()` todavía no manda `season_id`, así que `GameMatch::first()->season_id` es `null`; la aserción `assertSame(Season::current()->id, null)` falla con un mensaje claro de "Failed asserting that null is identical to X".

- [ ] **Step 3: Implementación mínima**

En `app/Console/Commands/ParseCod2Log.php`, agregar el import junto a los demás `use` del archivo:

```php
use App\Models\Season;
```

Y dentro de `openRound()` (línea ~266), cambiar:

```php
            $currentMatch = GameMatch::create([
                'server_id' => $server->id,
                'map' => $map,
                'gametype' => $gametype,
                'started_at' => now(),
            ]);
```

por:

```php
            $currentMatch = GameMatch::create([
                'server_id' => $server->id,
                'season_id' => Season::current()->id,
                'map' => $map,
                'gametype' => $gametype,
                'started_at' => now(),
            ]);
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/ParseCod2LogSeasonTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: mismo resultado que el baseline del Task 1 (sin regresiones nuevas).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ParseCod2Log.php tests/Feature/ParseCod2LogSeasonTest.php
git commit -m "Asignar season_id a cada partida nueva en ParseCod2Log::openRound()"
```

---

### Task 3: Panel de admin — cerrar temporada / iniciar una nueva

**Files:**
- Create: `app/Http/Controllers/Admin/SeasonController.php`
- Create: `resources/views/admin/seasons/index.blade.php`
- Modify: `routes/web.php` (agregar rutas `admin.seasons.index`/`admin.seasons.store`)
- Modify: `resources/views/layouts/admin.blade.php` (link "Temporadas" en el dropdown "Sistema")
- Test: `tests/Feature/Admin/SeasonControllerTest.php`

**Interfaces:**
- Consumes: `Season::current()`, `Season::matches()` (Task 1), `AdminAction::record(string $action, string $description): void` (ya existe en `app/Models/AdminAction.php`).
- Produces: `GET /adm_cod2/temporadas` (`admin.seasons.index`), `POST /adm_cod2/temporadas` (`admin.seasons.store`).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_the_active_season_and_past_seasons(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.seasons.index'));

        $response->assertOk();
        $response->assertSee('Temporada 1');
    }

    public function test_store_closes_the_current_season_and_opens_a_new_one(): void
    {
        $admin = User::factory()->create();
        $oldSeason = Season::current();

        $this->actingAs($admin)
            ->post(route('admin.seasons.store'), ['name' => 'Temporada 2'])
            ->assertRedirect();

        $oldSeason->refresh();
        $this->assertNotNull($oldSeason->ended_at);

        $newSeason = Season::current();
        $this->assertSame('Temporada 2', $newSeason->name);
        $this->assertNotSame($oldSeason->id, $newSeason->id);
    }

    public function test_store_requires_a_name(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.seasons.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        // La temporada activa no debe haber cambiado.
        $this->assertSame('Temporada 1', Season::current()->name);
    }

    public function test_store_records_an_admin_action(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.seasons.store'), ['name' => 'Temporada 2']);

        $this->assertTrue(AdminAction::where('action', 'seasons.close')->exists());
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/Admin/SeasonControllerTest.php`
Expected: FAIL — la ruta `admin.seasons.index` no existe (`RouteNotFoundException`).

- [ ] **Step 3: Agregar las rutas**

En `routes/web.php`, dentro del `Route::middleware('auth')->group(...)` del bloque `adm_cod2` (junto a las rutas de `backups`, ver el bloque que empieza con `Route::get('/respaldos', ...)`), agregar:

```php
        Route::get('/temporadas', [SeasonController::class, 'index'])->name('seasons.index');
        Route::post('/temporadas', [SeasonController::class, 'store'])->name('seasons.store');
```

Y agregar el `use` correspondiente junto a los demás imports de controllers admin al principio del archivo:

```php
use App\Http\Controllers\Admin\SeasonController;
```

- [ ] **Step 4: Escribir el controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Season;
use Illuminate\Http\Request;

class SeasonController extends Controller
{
    public function index()
    {
        $seasons = Season::withCount('matches')->orderByDesc('started_at')->get();

        return view('admin.seasons.index', compact('seasons'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $oldSeason = Season::current();

        $newSeason = \Illuminate\Support\Facades\DB::transaction(function () use ($oldSeason, $validated) {
            $oldSeason->update(['ended_at' => now()]);

            return Season::create([
                'name' => $validated['name'],
                'started_at' => now(),
                'ended_at' => null,
            ]);
        });

        AdminAction::record(
            'seasons.close',
            "Cerró \"{$oldSeason->name}\" e inició \"{$newSeason->name}\""
        );

        return redirect()->route('admin.seasons.index')->with('status', "Se inició \"{$newSeason->name}\".");
    }
}
```

- [ ] **Step 5: Escribir la vista**

```blade
@extends('layouts.admin')

@section('title', 'Temporadas')

@section('content')
<div class="space-y-4">
    <h1 class="text-lg font-semibold">Temporadas</h1>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <form method="POST" action="{{ route('admin.seasons.store') }}" class="p-4 flex flex-wrap items-end gap-3"
            onsubmit="return confirm('¿Cerrar la temporada activa e iniciar una nueva? Las partidas nuevas van a contar para la temporada nueva; la data de la temporada actual queda intacta y disponible.')">
            @csrf
            <div>
                <label for="name" class="block text-xs text-slate-500 mb-1">Nombre de la temporada nueva</label>
                <input type="text" name="name" id="name" required maxlength="100"
                    class="w-64 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm">
                @error('name')
                    <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Cerrar temporada actual e iniciar esta</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Nombre</th>
                    <th class="px-4 py-2 font-medium">Desde</th>
                    <th class="px-4 py-2 font-medium">Hasta</th>
                    <th class="px-4 py-2 font-medium">Partidas</th>
                </tr>
            </thead>
            <tbody>
                @foreach($seasons as $season)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">{{ $season->name }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $season->started_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">
                            @if($season->ended_at)
                                <span class="text-slate-400">{{ $season->ended_at->format('d/m/Y H:i') }}</span>
                            @else
                                <span class="text-emerald-400 text-xs font-medium">— activa —</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-400 tabular-nums">{{ $season->matches_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Agregar el link al nav de admin**

En `resources/views/layouts/admin.blade.php`, dentro del dropdown `admin-system-dropdown` (junto a "Respaldos"/"Discord"/"Contraseña"), agregar como primer link:

```blade
                            <a href="{{ route('admin.seasons.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Temporadas</a>
```

- [ ] **Step 7: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/Admin/SeasonControllerTest.php`
Expected: PASS (4 tests)

- [ ] **Step 8: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones nuevas respecto al baseline.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/SeasonController.php resources/views/admin/seasons/index.blade.php resources/views/layouts/admin.blade.php routes/web.php tests/Feature/Admin/SeasonControllerTest.php
git commit -m "Agregar panel de admin para cerrar/abrir temporadas"
```

---

### Task 4: Documentar y desplegar

**Files:**
- Modify: `CLAUDE.md` (documentar el sistema de temporadas, mismo estilo que el resto de la bitácora)

**Interfaces:**
- Consumes: nada nuevo — es el paso de cierre del sub-proyecto.

- [ ] **Step 1: Agregar una entrada en `CLAUDE.md`**

Agregar una sección nueva (después de "Servidores temporales self-service" o donde corresponda cronológicamente) documentando: qué es `seasons`/`matches.season_id`, que es 100% manual y global, que `/ranking`/`/especialidades` todavía NO son conscientes de temporada (queda para sub-proyecto 2/3), y un link a la spec (`docs/superpowers/specs/2026-08-25-temporadas-infraestructura-base-design.md`).

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "Documentar la infraestructura base de temporadas"
```

- [ ] **Step 3: Desplegar a producción**

Mismo flujo manual usado el resto de esta sesión (`deploy.sh` sigue apuntando al VPS viejo de respaldo, ver `CLAUDE.md`):

```bash
git archive HEAD | ssh cod2-vps-new "tar -x -C /var/www/cod2.4livepro.com"
ssh cod2-vps-new "chown -R www-data:www-data /var/www/cod2.4livepro.com && chmod +x /var/www/cod2.4livepro.com/artisan"
ssh cod2-vps-new "cd /var/www/cod2.4livepro.com && php artisan migrate --force && php artisan optimize"
ssh cod2-vps-new "chown -R www-data:www-data /var/www/cod2.4livepro.com"
```

- [ ] **Step 4: Verificar en producción**

Por `tinker` en el VPS real:

```php
App\Models\Season::current()->name; // "Temporada 1"
App\Models\GameMatch::whereNull('season_id')->count(); // 0
```

Y confirmar que `https://cod2.4livepro.com/adm_cod2/temporadas` responde `200` logueado como admin.
