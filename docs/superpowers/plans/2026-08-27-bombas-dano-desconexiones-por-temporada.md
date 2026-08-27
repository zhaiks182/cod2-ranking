# Bombas, daño y desconexiones por temporada Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que `/bombas`, `/dano`, y `/desconexiones` (las últimas 3 de 25 páginas de `/especialidades`) respeten la temporada elegida, igual que las otras 22 — trackeando `Bomb;`/`Damage;`/`Disconnected;` con vínculo a partida desde ahora en adelante.

**Architecture:** Tabla nueva `player_match_extras` (una fila por jugador+partida, con `bomb_plants`/`bomb_defuses`/`damage_dealt`/`damage_taken`/`mid_round_disconnects`), poblada por `ParseCod2Log::bumpServerStatExtra()` en paralelo al acumulador plano existente (`player_server_stats`, que no se toca). `?season=all` sigue leyendo `player_server_stats` (histórico completo real, sin huecos); cualquier temporada específica lee `player_match_extras` scopeada por `$matchIds`.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + Tailwind (CDN), MySQL en producción / SQLite en memoria para tests.

**Spec:** [docs/superpowers/specs/2026-08-27-bombas-dano-desconexiones-por-temporada-design.md](../specs/2026-08-27-bombas-dano-desconexiones-por-temporada-design.md)

## Global Constraints

- **Limitación inevitable, no un defecto:** solo se puede atribuir a una temporada específica lo que se juegue desde el deploy de este plan en adelante. Nada de lo jugado antes (incluida la Temporada 1 actual) se puede reconstruir por temporada — sigue existiendo únicamente en `?season=all`.
- `player_server_stats` no se toca ni se recalcula — sigue siendo el total histórico real de siempre, leído sin cambios por la rama `?season=all` de las 3 páginas.
- Una sola tabla nueva consolidada (`player_match_extras`), no tres separadas — mismo criterio que ya usa `bumpServerStatExtra()` (un método, cinco contadores relacionados).
- Sin cambios de vista: `resources/views/specialties/ranking.blade.php` (la plantilla compartida que usan `bombs()`/`damage()`/`disconnects()`) ya tiene `@isset($seasonId) @include('partials.season-selector', ...) @endisset` desde el sub-proyecto anterior — el selector aparece solo con que el controller pase `seasons`/`seasonId` a la vista.
- No se toca el parseo de las líneas `Bomb;`/`Damage;`/`Disconnected;` en sí (`recordBomb()`/`recordDamage()`/`recordDisconnect()`) — solo el destino de escritura (`bumpServerStatExtra()`).

---

### Task 1: Tabla y modelo `PlayerMatchExtra`

**Files:**
- Create: `database/migrations/2026_08_27_120000_create_player_match_extras_table.php`
- Create: `app/Models/PlayerMatchExtra.php`
- Test: `tests/Feature/PlayerMatchExtraTest.php`

**Interfaces:**
- Produces: tabla `player_match_extras` (`player_id`, `match_id`, `bomb_plants`, `bomb_defuses`, `damage_dealt`, `damage_taken`, `mid_round_disconnects`, unique `[player_id, match_id]`); modelo `App\Models\PlayerMatchExtra` con relaciones `player()`/`match()` y `$fillable`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\Server;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerMatchExtraTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_resolve_to_the_right_player_and_match(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $match = GameMatch::create([
            'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        $extra = PlayerMatchExtra::create([
            'player_id' => $player->id, 'match_id' => $match->id,
            'bomb_plants' => 2, 'bomb_defuses' => 1, 'damage_dealt' => 150, 'damage_taken' => 50, 'mid_round_disconnects' => 0,
        ]);

        $this->assertSame($player->id, $extra->player->id);
        $this->assertSame($match->id, $extra->match->id);
    }

    public function test_rejects_a_duplicate_player_and_match_pair(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $match = GameMatch::create([
            'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $match->id, 'bomb_plants' => 1]);

        $this->expectException(QueryException::class);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $match->id, 'bomb_plants' => 1]);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run (sin PHP local — sincronizar por `scp` a un clon descartable en el VPS, `cod2-vps-new:/root/sdd_baseline`, y correr por SSH, mismo flujo que planes anteriores):
`vendor/bin/phpunit tests/Feature/PlayerMatchExtraTest.php`
Expected: FAIL — la tabla `player_match_extras` no existe, el modelo tampoco.

- [ ] **Step 3: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por (jugador, partida) para Bomb;/Damage;/Disconnected; -- poblada por
 * ParseCod2Log::bumpServerStatExtra() en paralelo al acumulador plano existente
 * (player_server_stats, que no se toca). La temporada de cualquier fila se deriva
 * por join a matches.season_id, sin columna propia -- mismo criterio que ya usan
 * rounds/kills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_match_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedInteger('bomb_plants')->default(0);
            $table->unsignedInteger('bomb_defuses')->default(0);
            $table->unsignedInteger('damage_dealt')->default(0);
            $table->unsignedInteger('damage_taken')->default(0);
            $table->unsignedInteger('mid_round_disconnects')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_match_extras');
    }
};
```

- [ ] **Step 4: Escribir el modelo**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerMatchExtra extends Model
{
    protected $fillable = [
        'player_id', 'match_id', 'bomb_plants', 'bomb_defuses',
        'damage_dealt', 'damage_taken', 'mid_round_disconnects',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
```

- [ ] **Step 5: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/PlayerMatchExtraTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: mismo baseline que el resto del proyecto (115 tests, 1 fallo preexistente conocido de `ExampleTest.php`), sin regresiones nuevas — este task no modifica ningún código existente, solo agrega.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_27_120000_create_player_match_extras_table.php app/Models/PlayerMatchExtra.php tests/Feature/PlayerMatchExtraTest.php
git commit -m "Agregar tabla y modelo player_match_extras"
```

---

### Task 2: `ParseCod2Log` escribe en `player_match_extras`

**Files:**
- Modify: `app/Console/Commands/ParseCod2Log.php` (`bumpServerStatExtra()` y sus 3 call-sites: `recordBomb()`, `recordDamage()`, `recordDisconnect()`)
- Test: `tests/Feature/ParseCod2LogExtrasTest.php`

**Interfaces:**
- Consumes: `PlayerMatchExtra` (Task 1).
- Produces: `bumpServerStatExtra()` gana un parámetro `?int $matchId = null` (nullable, defensivo) — no cambia su firma pública de forma incompatible con nada externo, es un método privado.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\PlayerServerStat;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseCod2LogExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(string $logPath): Server
    {
        return Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => $logPath,
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    public function test_bomb_damage_and_disconnect_lines_populate_player_match_extras_and_keep_the_flat_counter(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Damage;222;1;Victim;axis;111;0;Attacker;allies;weapon_mp44;25;MOD_RIFLE_BULLET;torso_upper',
            '  0:35 Bomb;111;0;allies;Attacker;bomb_plant',
            '  0:40 Disconnected;111;0;Attacker',
            '',
        ]));
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $match = GameMatch::firstOrFail();
        $attacker = Player::where('guid', 111)->firstOrFail();
        $victim = Player::where('guid', 222)->firstOrFail();

        $attackerExtra = PlayerMatchExtra::where(['player_id' => $attacker->id, 'match_id' => $match->id])->firstOrFail();
        $this->assertSame(25, $attackerExtra->damage_dealt);
        $this->assertSame(0, $attackerExtra->damage_taken);
        $this->assertSame(1, $attackerExtra->bomb_plants);
        $this->assertSame(0, $attackerExtra->bomb_defuses);
        $this->assertSame(1, $attackerExtra->mid_round_disconnects);

        $victimExtra = PlayerMatchExtra::where(['player_id' => $victim->id, 'match_id' => $match->id])->firstOrFail();
        $this->assertSame(25, $victimExtra->damage_taken);
        $this->assertSame(0, $victimExtra->bomb_plants);

        // Regresion: el acumulador plano existente sigue actualizandose exactamente
        // igual que antes de este cambio -- ?season=all (Task 3) depende de esto.
        $attackerStat = PlayerServerStat::where(['player_id' => $attacker->id, 'server_id' => $server->id])->firstOrFail();
        $this->assertSame(25, $attackerStat->damage_dealt);
        $this->assertSame(1, $attackerStat->bomb_plants);
        $this->assertSame(1, $attackerStat->mid_round_disconnects);

        @unlink($logPath);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/ParseCod2LogExtrasTest.php`
Expected: FAIL — `PlayerMatchExtra::where(...)->firstOrFail()` no encuentra ninguna fila (el parser todavía no escribe ahí).

- [ ] **Step 3: Agregar el import**

En `app/Console/Commands/ParseCod2Log.php`, agregar junto a los demás `use App\Models\...`:

```php
use App\Models\PlayerMatchExtra;
```

- [ ] **Step 4: Modificar `bumpServerStatExtra()`**

Reemplazar el método completo (actualmente al final del archivo):

```php
    private function bumpServerStatExtra(
        Player $player,
        int $serverId,
        ?int $matchId = null,
        int $bombPlants = 0,
        int $bombDefuses = 0,
        int $damageDealt = 0,
        int $damageTaken = 0,
        int $midRoundDisconnects = 0,
    ): void {
        $stat = PlayerServerStat::firstOrCreate(['player_id' => $player->id, 'server_id' => $serverId]);

        $stat->bomb_plants += $bombPlants;
        $stat->bomb_defuses += $bombDefuses;
        $stat->damage_dealt += $damageDealt;
        $stat->damage_taken += $damageTaken;
        $stat->mid_round_disconnects += $midRoundDisconnects;
        $stat->save();

        if ($matchId === null) {
            return;
        }

        $extra = PlayerMatchExtra::firstOrCreate(['player_id' => $player->id, 'match_id' => $matchId]);
        $extra->bomb_plants += $bombPlants;
        $extra->bomb_defuses += $bombDefuses;
        $extra->damage_dealt += $damageDealt;
        $extra->damage_taken += $damageTaken;
        $extra->mid_round_disconnects += $midRoundDisconnects;
        $extra->save();
    }
```

- [ ] **Step 5: Pasar `$currentRound->match_id` en los 3 call-sites**

En `recordDisconnect()`:

```php
        if ($player) {
            $this->bumpServerStatExtra($player, $server->id, $currentRound->match_id, midRoundDisconnects: 1);
        }
```

En `recordBomb()`:

```php
        if ($action === 'bomb_plant') {
            $this->bumpServerStatExtra($player, $server->id, $currentRound->match_id, bombPlants: 1);
        } elseif ($action === 'bomb_defuse') {
            $this->bumpServerStatExtra($player, $server->id, $currentRound->match_id, bombDefuses: 1);
        }
```

En `recordDamage()` (dos llamadas, atacante y víctima):

```php
        $attacker = $this->upsertPlayer($aGuid, $this->toUtf8($aName));
        if ($attacker) {
            $this->bumpServerStatExtra($attacker, $server->id, $currentRound->match_id, damageDealt: (int) $damage);
        }

        $victim = $this->upsertPlayer($vGuid, $this->toUtf8($vName));
        if ($victim) {
            $this->bumpServerStatExtra($victim, $server->id, $currentRound->match_id, damageTaken: (int) $damage);
        }
```

Confirmar con `grep -n "match_id" app/Models/Round.php` que `Round` expone `match_id` como atributo directo (columna de la tabla, no una relación) — debería, ya que `rounds.match_id` es una FK simple.

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/ParseCod2LogExtrasTest.php`
Expected: PASS (1 test, 8 assertions)

- [ ] **Step 7: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones nuevas (baseline + 3 tests de Task 1 y 2, 1 fallo preexistente conocido).

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/ParseCod2Log.php tests/Feature/ParseCod2LogExtrasTest.php
git commit -m "ParseCod2Log escribe player_match_extras junto al acumulador plano existente"
```

---

### Task 3: `bombs()`, `damage()`, `disconnects()` — season-scoped

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/ExtrasSeasonTest.php`

**Interfaces:**
- Consumes: `resolveSeason()` (ya existe), `PlayerMatchExtra` (Task 1).
- No requiere cambios de vista — `specialties/ranking.blade.php` ya renderiza el selector con solo recibir `seasons`/`seasonId` (ver Global Constraints).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Specialties;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\PlayerServerStat;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtrasSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function match(int $seasonId): GameMatch
    {
        return GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $seasonId,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
        ]);
    }

    public function test_bombs_excludes_old_season_and_all_falls_back_to_the_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $oldMatch = $this->match($oldSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $oldMatch->id, 'bomb_plants' => 3, 'bomb_defuses' => 0]);

        // Total historico de ANTES de este feature -- nunca tuvo fila en
        // player_match_extras, solo vive en el acumulador plano (simula datos reales
        // de antes del deploy, que "todo el historial" nunca debe perder).
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'bomb_plants' => 10, 'bomb_defuses' => 2]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $newMatch = $this->match($newSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $newMatch->id, 'bomb_plants' => 5, 'bomb_defuses' => 1]);

        $response = $this->get(route('specialties.bombs', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertNotNull($row);
        $this->assertSame(5, $row->value); // solo la temporada activa (nueva)

        $responseAll = $this->get(route('specialties.bombs', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertSame(10, $rowAll->value); // 'all' lee PlayerServerStat, no 3+5
    }

    public function test_damage_excludes_old_season_and_all_falls_back_to_the_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $oldMatch = $this->match($oldSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $oldMatch->id, 'damage_dealt' => 300]);
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'damage_dealt' => 1000]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $newMatch = $this->match($newSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $newMatch->id, 'damage_dealt' => 450]);

        $response = $this->get(route('specialties.damage', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertNotNull($row);
        $this->assertSame(number_format(450), $row->value);

        $responseAll = $this->get(route('specialties.damage', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertSame(number_format(1000), $rowAll->value);
    }

    public function test_disconnects_excludes_old_season_and_all_falls_back_to_the_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $oldMatch = $this->match($oldSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $oldMatch->id, 'mid_round_disconnects' => 2]);
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'mid_round_disconnects' => 7]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $newMatch = $this->match($newSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $newMatch->id, 'mid_round_disconnects' => 3]);

        $response = $this->get(route('specialties.disconnects', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertNotNull($row);
        $this->assertSame(3, $row->value);

        $responseAll = $this->get(route('specialties.disconnects', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertSame(7, $rowAll->value);
    }

    public function test_bombs_page_shows_the_season_selector(): void
    {
        $response = $this->get(route('specialties.bombs', ['server' => $this->server->slug]));
        $response->assertOk();
        $response->assertSee('specialty-season-dropdown', false);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/Specialties/ExtrasSeasonTest.php`
Expected: FAIL — hoy las 3 rutas ignoran `?season=`, así que `test_bombs_excludes_old_season...` ve `value=8` (3+5, todas las partidas) en el caso sin filtro, no `5`; y `test_bombs_page_shows_the_season_selector` falla porque la vista nunca recibe `seasonId`.

- [ ] **Step 3: Agregar el import**

En `app/Http/Controllers/SpecialtyController.php`, agregar junto a los demás `use App\Models\...` (orden alfabético, después de `Player`):

```php
use App\Models\PlayerMatchExtra;
```

- [ ] **Step 4: Reescribir `bombs()`**

```php
    public function bombs(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalPlants = 0;
        $totalDefuses = 0;

        if ($server) {
            if ($seasonId === 'all') {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where('bomb_plants', '>', 0)
                    ->whereHas('player')
                    ->orderByDesc('bomb_plants')
                    ->limit(50)
                    ->get()
                    ->map(function ($row) {
                        $row->value = $row->bomb_plants;
                        $row->share = null;

                        return $row;
                    });

                $totals = PlayerServerStat::where('server_id', $server->id)
                    ->selectRaw('sum(bomb_plants) as p, sum(bomb_defuses) as d')->first();
                $totalPlants = (int) ($totals->p ?? 0);
                $totalDefuses = (int) ($totals->d ?? 0);
            } else {
                $serverMatchIds = GameMatch::where('server_id', $server->id)->whereIn('id', $matchIds)->pluck('id');

                $tally = PlayerMatchExtra::whereIn('match_id', $serverMatchIds)
                    ->where('bomb_plants', '>', 0)
                    ->selectRaw('player_id, sum(bomb_plants) as bomb_plants')
                    ->groupBy('player_id')
                    ->orderByDesc('bomb_plants')
                    ->limit(50)
                    ->get();

                $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');

                $rows = $tally->map(function ($row) use ($players) {
                    $player = $players[$row->player_id] ?? null;

                    return $player ? (object) ['player' => $player, 'value' => (int) $row->bomb_plants, 'share' => null] : null;
                })->filter()->values();

                $totalPlants = (int) PlayerMatchExtra::whereIn('match_id', $serverMatchIds)->sum('bomb_plants');
                $totalDefuses = (int) PlayerMatchExtra::whereIn('match_id', $serverMatchIds)->sum('bomb_defuses');
            }
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.bombs', 'icon' => '💣', 'title' => 'Especialistas en Bombas',
            'subtitle' => 'Más bombas plantadas (Search and Destroy)',
            'valueLabel' => 'plantadas', 'valueColor' => 'text-red-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Bombas plantadas', 'value' => $totalPlants, 'color' => 'text-red-400'],
                ['label' => 'Bombas desactivadas', 'value' => $totalDefuses, 'color' => 'text-emerald-400'],
            ],
        ]);
    }
```

- [ ] **Step 5: Reescribir `damage()`**

```php
    public function damage(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalDamage = 0;

        if ($server) {
            if ($seasonId === 'all') {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where('damage_dealt', '>', 0)
                    ->whereHas('player')
                    ->orderByDesc('damage_dealt')
                    ->limit(50)
                    ->get()
                    ->map(function ($row) {
                        $row->value = number_format($row->damage_dealt);
                        $row->share = null;

                        return $row;
                    });

                $totalDamage = (int) PlayerServerStat::where('server_id', $server->id)->sum('damage_dealt');
            } else {
                $serverMatchIds = GameMatch::where('server_id', $server->id)->whereIn('id', $matchIds)->pluck('id');

                $tally = PlayerMatchExtra::whereIn('match_id', $serverMatchIds)
                    ->where('damage_dealt', '>', 0)
                    ->selectRaw('player_id, sum(damage_dealt) as damage_dealt')
                    ->groupBy('player_id')
                    ->orderByDesc('damage_dealt')
                    ->limit(50)
                    ->get();

                $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');

                $rows = $tally->map(function ($row) use ($players) {
                    $player = $players[$row->player_id] ?? null;

                    return $player ? (object) ['player' => $player, 'value' => number_format((int) $row->damage_dealt), 'share' => null] : null;
                })->filter()->values();

                $totalDamage = (int) PlayerMatchExtra::whereIn('match_id', $serverMatchIds)->sum('damage_dealt');
            }
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.damage', 'icon' => '💥', 'title' => 'Especialistas en Daño',
            'subtitle' => 'Más daño infligido en total (Search and Destroy)',
            'valueLabel' => 'daño', 'valueColor' => 'text-amber-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Daño total infligido', 'value' => number_format($totalDamage), 'color' => 'text-amber-400'],
            ],
        ]);
    }
```

- [ ] **Step 6: Reescribir `disconnects()`**

```php
    public function disconnects(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();

        if ($server) {
            if ($seasonId === 'all') {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where('mid_round_disconnects', '>', 0)
                    ->whereHas('player')
                    ->orderByDesc('mid_round_disconnects')
                    ->limit(50)
                    ->get()
                    ->map(function ($row) {
                        $row->value = $row->mid_round_disconnects;
                        $row->share = null;

                        return $row;
                    });
            } else {
                $serverMatchIds = GameMatch::where('server_id', $server->id)->whereIn('id', $matchIds)->pluck('id');

                $tally = PlayerMatchExtra::whereIn('match_id', $serverMatchIds)
                    ->where('mid_round_disconnects', '>', 0)
                    ->selectRaw('player_id, sum(mid_round_disconnects) as mid_round_disconnects')
                    ->groupBy('player_id')
                    ->orderByDesc('mid_round_disconnects')
                    ->limit(50)
                    ->get();

                $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');

                $rows = $tally->map(function ($row) use ($players) {
                    $player = $players[$row->player_id] ?? null;

                    return $player ? (object) ['player' => $player, 'value' => (int) $row->mid_round_disconnects, 'share' => null] : null;
                })->filter()->values();
            }
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.disconnects', 'icon' => '🔌', 'title' => 'Se Fueron a Media Ronda',
            'subtitle' => 'Desconexiones mientras la ronda seguía activa (Search and Destroy)',
            'valueLabel' => 'desconexiones', 'valueColor' => 'text-rose-400',
            'shareLabel' => null,
            'statCards' => [],
        ]);
    }
```

- [ ] **Step 7: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/Specialties/ExtrasSeasonTest.php`
Expected: PASS (4 tests)

- [ ] **Step 8: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones nuevas.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/ExtrasSeasonTest.php
git commit -m "Scopear bombas, dano y desconexiones por temporada (desde ahora en adelante)"
```

---

### Task 4: Documentar, backup, y desplegar

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Agregar una entrada en `CLAUDE.md`**

Mismo estilo que las entradas "Ranking por temporada"/"Especialidades por temporada" ya existentes: qué cambió (bombas/daño/desconexiones ahora respetan temporada, con la limitación de "solo desde el deploy en adelante" explicada), la tabla nueva, el parser, link al spec.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "Documentar bombas, dano y desconexiones por temporada"
```

- [ ] **Step 3: Backup de producción ANTES de desplegar**

Mismo procedimiento que los planes anteriores: `php artisan backup:run` (dump de BD) + tarball completo de `/var/www/cod2.4livepro.com`. Verificar `df -h /` antes de continuar (no proceder si el uso pasa 90%).

- [ ] **Step 4: Migrar y desplegar selectivo (NO `git archive` completo)**

Correr la migración nueva contra producción (`php artisan migrate --force` desde el server, tabla nueva, sin tocar ninguna existente — bajo riesgo, pero de todas formas verificar el resultado). Desplegar por `scp` los 3 archivos que este plan tocó (`ParseCod2Log.php`, `SpecialtyController.php`, y el modelo nuevo `PlayerMatchExtra.php`) — nunca `routes/web.php` (nadie de este plan lo toca).

**Importante — el parser corre cada minuto en producción real:** después de subir `ParseCod2Log.php`, confirmar con `php -l` que no tiene errores de sintaxis ANTES de que corra el cron, y revisar `journalctl`/logs de Laravel después de la primera corrida real para confirmar que no tira excepciones.

- [ ] **Step 5: Verificar en producción**

`curl` a `/bombas`, `/dano`, `/desconexiones` (con y sin `?season=all`) — deben dar `200`. Confirmar que el selector de temporada aparece (`grep` del HTML por `specialty-season-dropdown`). Esperar a que se juegue una partida real (o generar una de prueba) y confirmar con `tinker` que `player_match_extras` recibe filas nuevas.

- [ ] **Step 6: Rollback, si algo falla**

Re-desplegar (vía `scp`, no `git archive`) las versiones anteriores de los 2 archivos modificados. La migración es aditiva (tabla nueva) — un `down()` la eliminaría sin afectar nada más, pero no hace falta revertirla salvo que el problema esté en el esquema en sí.
