# Ranking por temporada (Sub-proyecto 2 de 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que `/ranking` y `/jugadores/{guid}` respeten la temporada elegida (activa por defecto, cualquier cerrada, o "Todo el historial"), calculando las stats al vuelo desde `kills`/`rounds`/`matches` en vez de leer las tablas pre-calculadas `player_server_stats`/`player_map_stats`/`players.kills_total`.

**Architecture:** Un scope nuevo `GameMatch::scopeForSeason($seasonId)` centraliza "qué partidas cuentan para esta temporada" (filtro por `season_id` + exclusión de partidas abandonadas). Tanto `LeaderboardController` como `PlayerController` calculan `$matchIds = GameMatch::forSeason($seasonId)->pluck('id')` una vez y lo usan como `whereIn('match_id', $matchIds)` en sus queries contra `kills`/`rounds` (ambas tablas ya tienen `match_id` directo, sin necesidad de join extra para este filtro). `KillAggregator` (ya existe) se extiende con un método nuevo para agrupar por mapa en vez de por jugador.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + Tailwind (CDN), MySQL en producción / SQLite en memoria para tests.

**Spec:** [docs/superpowers/specs/2026-08-25-ranking-por-temporada-design.md](../specs/2026-08-25-ranking-por-temporada-design.md)

## Global Constraints

- El selector de temporada **reemplaza** al filtro de fecha manual (`from`/`to`) en `/ranking` — se saca de la UI pública. El mecanismo interno de "elegir una sesión específica de un mapa con varias fechas" (las date-pills) se mantiene sin cambios, es independiente del selector de temporada.
- **Default en TODO el sitio (no solo `/ranking`): la temporada activa.** Cualquier link a `/jugadores/{guid}` sin `?season=` explícito muestra la temporada activa, no el histórico.
- URL: `?season={id}` (temporada específica) o `?season=all` ("Todo el historial"). Sin el parámetro → temporada activa (`Season::current()->id`).
- Las partidas abandonadas sin resultado real (`GameMatch::abandonedWithoutConclusion()`, ya existe) se excluyen SIEMPRE, sin importar la temporada elegida — incluido en `season=all`.
- `player_server_stats`, `player_map_stats`, `players.kills_total`/`deaths_total`/etc. **no se tocan, no se borran** — dejan de leerse desde estas dos vistas, nada más.
- Sin tabla de acumuladores nueva para stats por temporada — todo se calcula al vuelo con queries.
- Backup (dump de base de datos real + tarball de código) antes de correr la migración/deploy real — ver Task 6.

---

### Task 1: `player_weapon_picks.season_id`

**Files:**
- Create: `database/migrations/2026_08_25_190000_add_season_id_to_player_weapon_picks.php`
- Modify: `app/Console/Commands/ParseCod2Log.php:526` (donde se hace `PlayerWeaponPick::firstOrCreate(...)`)
- Test: `tests/Feature/PlayerWeaponPickSeasonTest.php`

**Interfaces:**
- Consumes: `Season::current(): Season` (ya existe, sub-proyecto 1).
- Produces: `player_weapon_picks.season_id` (columna, nullable a nivel de esquema, poblada siempre por `ParseCod2Log`). Unique constraint `[player_id, weapon, season_id]`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\PlayerWeaponPick;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerWeaponPickSeasonTest extends TestCase
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

    private function pickupLog(): string
    {
        return implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:05 J;0;12345;DESTINATION',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Weapon;0;12345;DESTINATION;weapon_mp44',
            '',
        ]);
    }

    public function test_a_new_weapon_pick_gets_the_currently_active_season(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $this->pickupLog());
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $pick = PlayerWeaponPick::first();
        $this->assertNotNull($pick);
        $this->assertSame(Season::current()->id, $pick->season_id);

        @unlink($logPath);
    }

    public function test_a_pick_after_closing_a_season_gets_the_new_one_as_a_separate_row(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $this->pickupLog());
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();
        $oldPick = PlayerWeaponPick::first();
        $oldSeasonId = $oldPick->season_id;

        Season::current()->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        // Mismo jugador, misma arma, log nuevo -- debe crear una fila NUEVA (season_id
        // distinto), no incrementar la de la temporada vieja.
        file_put_contents($logPath, $logPath); // no-op, se reabre el mismo archivo
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_railyard\protocol\120\shortversion\1.4.6.8',
            '  0:05 J;0;12345;DESTINATION',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_railyard\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Weapon;0;12345;DESTINATION;weapon_mp44',
            '',
        ]));

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(2, PlayerWeaponPick::count());
        $newPick = PlayerWeaponPick::where('season_id', $newSeason->id)->first();
        $this->assertNotNull($newPick);
        $this->assertSame(1, $newPick->picks);
        $this->assertSame(1, PlayerWeaponPick::find($oldPick->id)->fresh()->picks);

        @unlink($logPath);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run (sin PHP local — sincronizar por `scp` a un clon descartable en `cod2-vps-new:/root/sdd_baseline` y correr por SSH, ver la nota de la Task 6 sobre el flujo completo):
`vendor/bin/phpunit tests/Feature/PlayerWeaponPickSeasonTest.php`
Expected: FAIL — `pick->season_id` es `null` (la columna existe recién después del Step 3, así que en realidad el primer fallo es que la tabla no tiene esa columna todavía / el modelo no la conoce).

- [ ] **Step 3: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mismo patron que matches.season_id (sub-proyecto 1 de temporadas) --
 * nullable a nivel de esquema (doctrine/dbal no instalado, SQLite no
 * soporta bien Blueprint::change() sin reconstruir la tabla), poblada
 * siempre por codigo de aplicacion (ParseCod2Log). El unique constraint
 * pasa a incluir season_id -- un jugador puede tener un arma "mas
 * equipada" distinta por temporada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_weapon_picks', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'weapon']);
            $table->unsignedBigInteger('season_id')->nullable()->after('player_id');
            $table->foreign('season_id')->references('id')->on('seasons');
        });

        $temporada1Id = DB::table('seasons')->orderBy('id')->value('id');

        if ($temporada1Id) {
            DB::table('player_weapon_picks')->update(['season_id' => $temporada1Id]);
        }

        Schema::table('player_weapon_picks', function (Blueprint $table) {
            $table->unique(['player_id', 'weapon', 'season_id']);
        });
    }

    public function down(): void
    {
        Schema::table('player_weapon_picks', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'weapon', 'season_id']);
            $table->dropForeign(['season_id']);
            $table->dropColumn('season_id');
            $table->unique(['player_id', 'weapon']);
        });
    }
};
```

- [ ] **Step 4: Modificar `PlayerWeaponPick`**

En `app/Models/PlayerWeaponPick.php`, agregar `'season_id'` a `$fillable`:

```php
    protected $fillable = ['player_id', 'season_id', 'weapon', 'picks'];
```

- [ ] **Step 5: Modificar `ParseCod2Log`**

En `app/Console/Commands/ParseCod2Log.php` línea 526, cambiar:

```php
        $pick = PlayerWeaponPick::firstOrCreate(['player_id' => $player->id, 'weapon' => $weapon]);
```

por:

```php
        $pick = PlayerWeaponPick::firstOrCreate([
            'player_id' => $player->id,
            'weapon' => $weapon,
            'season_id' => Season::current()->id,
        ]);
```

Confirmar que `use App\Models\Season;` ya está importado en este archivo (lo agregó el sub-proyecto 1, Task 2) — si no, agregarlo junto a los demás `use`.

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/PlayerWeaponPickSeasonTest.php`
Expected: PASS (2 tests)

- [ ] **Step 7: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: mismo resultado que el baseline (58 tests, 1 fallo preexistente conocido de `ExampleTest.php`), sin regresiones nuevas.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_25_190000_add_season_id_to_player_weapon_picks.php app/Models/PlayerWeaponPick.php app/Console/Commands/ParseCod2Log.php tests/Feature/PlayerWeaponPickSeasonTest.php
git commit -m "Agregar season_id a player_weapon_picks"
```

---

### Task 2: `GameMatch::scopeForSeason()` + `/ranking` scopeado por temporada (backend)

**Files:**
- Modify: `app/Models/GameMatch.php` (nuevo scope `forSeason`)
- Modify: `app/Http/Controllers/LeaderboardController.php`
- Test: `tests/Feature/LeaderboardSeasonTest.php`

**Interfaces:**
- Consumes: `Season::current()`, `GameMatch::abandonedWithoutConclusion()` (ya existen).
- Produces: `GameMatch::forSeason(int|string $seasonId)` — query scope; `$seasonId` es un id de temporada o el string literal `'all'`. Devuelve las partidas de esa temporada (o todas si `'all'`) que NO están abandonadas sin resultado. Usado también por Task 4.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);
    }

    /** Partida real que llegó a 13 rondas (cuenta) — crea 1 kill de $attacker contra $victim. */
    private function realMatch(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => $map,
                'gametype' => 'sd',
                'started_at' => now(),
                'ended_at' => now(),
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    /** Partida abandonada: solo 2 rondas, sin MatchEnd -- no debe contar. */
    private function abandonedMatch(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $round = Round::create([
            'server_id' => $this->server->id,
            'match_id' => $match->id,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_ranking_without_season_param_shows_only_the_active_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->kills); // solo las 2 de Temporada 2 (la activa), no la 1 de Temporada 1
    }

    public function test_ranking_with_season_all_shows_every_season_combined(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'season' => 'all']));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2, $row->kills); // las 2 partidas, de las 2 temporadas
    }

    public function test_ranking_excludes_abandoned_matches_in_any_season(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($season->id, $attacker, $victim);
        $this->abandonedMatch($season->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(1, $row->kills); // solo la real, la abandonada no suma
    }

    public function test_ranking_for_a_specific_closed_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'season' => $oldSeason->id]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2, $row->kills); // las 2 de Temporada 1, no la de Temporada 2
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/LeaderboardSeasonTest.php`
Expected: FAIL — hoy `/ranking` sin parámetros lee `PlayerServerStat` (de por vida, sin filtro de temporada), así que el primer test ve `kills=3` (todas las partidas) en vez de `2`.

- [ ] **Step 3: Agregar el scope a `GameMatch`**

En `app/Models/GameMatch.php`, agregar (junto a los otros scopes, después de `scopeAbandonedWithoutConclusion`):

```php
    /**
     * Que partidas cuentan para una temporada dada -- $seasonId es un id real o el
     * string literal 'all' (todas las temporadas juntas). Las partidas abandonadas
     * sin resultado real se excluyen SIEMPRE, sin importar la temporada (incluido
     * en 'all') -- mismo criterio que ya usa StatsRecalculator para los
     * acumuladores de por vida.
     */
    public function scopeForSeason($query, $seasonId)
    {
        if ($seasonId !== 'all') {
            $query->where('season_id', $seasonId);
        }

        return $query->whereNotIn('id', static::abandonedWithoutConclusion()->pluck('id'));
    }
```

- [ ] **Step 4: Reescribir `LeaderboardController`**

Reemplazar el archivo completo `app/Http/Controllers/LeaderboardController.php` por:

```php
<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use App\Support\TeamSideAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();

        $seasons = Season::orderByDesc('started_at')->get();
        $seasonParam = $request->query('season');
        $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        // Normalizado siempre, aunque venga un codigo crudo de variante en la URL
        // (bookmark viejo, por ejemplo) — ver buildMapGroups()/MapCatalog::mergeVariants
        // para el porque: mp_dawnville_fix y mp_dawnville_sun son el mismo mapa real
        // (St. Mere Eglise) y desde 2026-08-19 comparten una sola pestaña.
        $map = $request->query('map') ? MapCatalog::normalize($request->query('map')) : null;

        $mapGroups = $server ? $this->buildMapGroups($server->id, $matchIds) : collect();

        // Los codigos crudos (variantes) que hay que incluir en cada query de abajo
        // para que la pestaña combinada muestre datos de TODAS sus variantes, no solo
        // la que casualmente quedo de ultima.
        $mapCodes = $map ? ($mapGroups[$map]->codes ?? [$map]) : [];

        // A map played across more than one calendar day can't honestly show one
        // combined "all sessions" total (see the class-level note on buildMapGroups) —
        // picking that map's tab defaults to its most recent session within the
        // selected season/scope instead of silently mixing every session together.
        $from = $to = null;
        if ($map && ($mapGroups[$map]->dates ?? collect())->count() > 1) {
            $latestDate = $mapGroups[$map]->dates->last()->toDateString();
            $from = $to = $latestDate;
        }

        $rows = $server ? $this->aggregateFromKills($server->id, $mapCodes, $matchIds) : collect();

        // Any map tab normally corresponds to exactly one played match (or, for a
        // multi-session map, one specific session once the date default above kicks
        // in) — show the same axis/allies breakdown as that match's own detail page,
        // so the ranking view doesn't need a trip to /partidas just to see who won
        // which side.
        $axisRows = collect();
        $alliesRows = collect();
        $sideScores = ['axis' => null, 'allies' => null, 'winning' => null];

        if ($server && $map) {
            $rounds = Round::where('rounds.server_id', $server->id)->whereIn('rounds.map', $mapCodes)->where('rounds.gametype', 'sd')
                ->whereIn('rounds.match_id', $matchIds)
                ->when($from, fn ($q) => $q->join('matches', 'matches.id', '=', 'rounds.match_id')
                    ->where('matches.started_at', '>=', Carbon::parse($from)->startOfDay())
                    ->where('matches.started_at', '<=', Carbon::parse($to)->endOfDay()))
                ->orderBy('rounds.id')
                ->select('rounds.*')
                ->get();

            [$axisRows, $alliesRows, $sideByPlayerId] = TeamSideAnalyzer::splitByCurrentSide($rounds, $rows);
            $sideScores = TeamSideAnalyzer::sideScores($rounds, $sideByPlayerId);

            if (! $rounds->last()?->ended_at) {
                $sideScores['winning'] = null;
            }
        }

        return view('leaderboard', compact('servers', 'server', 'seasons', 'seasonId', 'mapGroups', 'map', 'mapCodes', 'rows', 'axisRows', 'alliesRows', 'sideScores'));
    }

    /**
     * One entry per REAL map (variant codes like mp_dawnville_fix/mp_dawnville_sun
     * merged under the same normalized key since 2026-08-19 — same map, same tab,
     * see MapCatalog::normalize()), each holding the sorted list of calendar days
     * it's been played on and the raw codes that contributed to it, SCOPED to the
     * selected season's matches ($matchIds) — a map's date-pills inside a season
     * shouldn't include sessions from a different season.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $matchIds
     * @return \Illuminate\Support\Collection<string, object{dates: \Illuminate\Support\Collection<int, \Carbon\Carbon>, codes: array<int, string>}>
     */
    private function buildMapGroups(int $serverId, $matchIds)
    {
        $sessions = GameMatch::where('server_id', $serverId)
            ->where('is_backfilled', false)
            ->where('gametype', 'sd')
            ->whereIn('id', $matchIds)
            ->selectRaw('map, DATE(started_at) as play_date')
            ->distinct()
            ->get()
            ->groupBy(fn ($row) => MapCatalog::normalize($row->map));

        $groups = $sessions->map(fn ($rows) => (object) [
            'dates' => $rows->pluck('play_date')->unique()->map(fn ($d) => Carbon::parse($d))->sort()->values(),
            'codes' => $rows->pluck('map')->unique()->values()->all(),
        ]);

        // Requested tab order: Toujane, Burgundy, Dawnville, Stalingrad first (in that
        // order), then whatever other maps have been played, alphabetically by label.
        $priority = ['mp_toujane', 'mp_burgundy', 'mp_dawnville', 'mp_railyard'];

        return $groups->sortBy(function ($group, $mapCode) use ($priority) {
            $rank = array_search($mapCode, $priority, true);

            return $rank !== false
                ? sprintf('0_%02d', $rank)
                : '1_'.MapCatalog::mapLabel($mapCode);
        });
    }

    /**
     * Unico camino de calculo del ranking desde 2026-08-25 (antes: tablas
     * pre-calculadas player_server_stats/player_map_stats para la vista sin fecha,
     * este metodo solo para el filtro de fecha manual). kills.match_id ya existe
     * directo en la tabla, asi que $matchIds se aplica sin necesidad de otro join
     * mas alla del que ya hace falta para gametype/map (rounds).
     *
     * @param  array<int, string>  $mapCodes  Raw map codes to include (all variants of
     *                                        the selected normalized map, or empty for "General").
     * @param  \Illuminate\Support\Collection<int, int>  $matchIds
     */
    private function aggregateFromKills(int $serverId, array $mapCodes, $matchIds)
    {
        return KillAggregator::aggregate(function () use ($serverId, $mapCodes, $matchIds) {
            // The ranking is Search & Destroy only — a DM/HQ/CTF session shouldn't
            // contribute to it (see StatsRecalculator / ParseCod2Log for the same rule
            // applied to the cached player_map_stats/player_server_stats tables).
            $q = Kill::query()
                ->join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('rounds.server_id', $serverId)->where('rounds.gametype', 'sd')
                ->whereIn('kills.match_id', $matchIds);
            if ($mapCodes) {
                $q->whereIn('rounds.map', $mapCodes);
            }

            return $q;
        });
    }
}
```

**Nota:** este reemplazo elimina el filtro de fecha manual (`from`/`to` como parámetros de URL/UI) y las lecturas de `PlayerServerStat`/`PlayerMapStat` — ambos imports (`App\Models\PlayerMapStat`, `App\Models\PlayerServerStat`) se sacan del archivo porque ya no se usan. El mecanismo interno de "sesión más reciente de un mapa multi-sesión" se mantiene (usa `$from`/`$to` como variables locales, ya no de la URL).

- [ ] **Step 5: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/LeaderboardSeasonTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones nuevas respecto al baseline del Task 1 (la vista `leaderboard.blade.php` todavía referencia variables viejas como `$from`/`$to`/`$usingDateFilter` que ya no se pasan — **esto va a romper la renderización de la vista real hasta el Task 3**; si algún test HTTP existente visita `/ranking` y renderiza la vista completa, va a fallar hasta que el Task 3 esté hecho. Si eso pasa, es esperable — dejarlo así y continuar al Task 3 en la misma sesión de trabajo antes de dar este paso por cerrado. Los tests de este Task usan `$response->viewData(...)`, que no requiere que el Blade renderice sin errores para leer las variables — deberían pasar igual).

- [ ] **Step 7: Commit**

```bash
git add app/Models/GameMatch.php app/Http/Controllers/LeaderboardController.php tests/Feature/LeaderboardSeasonTest.php
git commit -m "Scopear /ranking por temporada (backend) y GameMatch::forSeason()"
```

---

### Task 3: `/ranking` — vista con selector de temporada

**Files:**
- Create: `resources/views/partials/season-selector.blade.php`
- Modify: `resources/views/leaderboard.blade.php`

**Interfaces:**
- Consumes: `$seasons` (Collection de `Season`, más reciente primero), `$seasonId` (int o `'all'`) — ambos producidos por `LeaderboardController::index()` (Task 2).
- Produces: partial reusable `partials.season-selector`, consumido también por Task 5 (`players/show.blade.php`).

- [ ] **Step 1: Escribir el partial del selector**

```blade
{{--
    Selector de temporada reusable -- requiere:
    - $seasons: Collection de Season, mas reciente primero
    - $seasonId: int o 'all', la seleccionada actualmente
    - $seasonBaseRoute: nombre de ruta (ej. 'leaderboard', 'players.show')
    - $seasonBaseParams: array de parametros a preservar en el link (sin 'season')
    - $seasonDropdownId: id unico del dropdown en esta pagina (puede haber mas de un
      selector en la misma pagina si se reusa el partial dos veces)
--}}
<div class="relative">
    <button type="button" onclick="document.getElementById('{{ $seasonDropdownId }}').classList.toggle('hidden')"
        class="px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-500 hover:text-cyan-400 text-sm flex items-center gap-1.5">
        @if($seasonId === 'all')
            Todo el historial
        @else
            {{ $seasons->firstWhere('id', $seasonId)?->name ?? 'Temporada' }}
        @endif
        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
    </button>
    <div id="{{ $seasonDropdownId }}" class="hidden absolute right-0 mt-2 w-52 bg-panel border border-slate-800 shadow-xl py-1 z-50 rounded-lg text-sm">
        @foreach($seasons as $season)
            <a href="{{ route($seasonBaseRoute, array_merge($seasonBaseParams, ['season' => $season->id])) }}"
                class="block px-3 py-2 {{ $seasonId === $season->id ? 'text-cyan-400' : 'text-slate-300' }} hover:bg-gsprimary/20 hover:text-gsaccent">
                {{ $season->name }}
                @if(! $season->ended_at)<span class="text-[10px] text-emerald-400 ml-1">activa</span>@endif
            </a>
        @endforeach
        <a href="{{ route($seasonBaseRoute, array_merge($seasonBaseParams, ['season' => 'all'])) }}"
            class="block px-3 py-2 border-t border-slate-800 mt-1 pt-2 {{ $seasonId === 'all' ? 'text-cyan-400' : 'text-slate-300' }} hover:bg-gsprimary/20 hover:text-gsaccent">
            Todo el historial
        </a>
    </div>
</div>
```

- [ ] **Step 2: Modificar `leaderboard.blade.php`**

Reemplazar el archivo completo por:

```blade
@extends('layouts.app')

@section('title', 'Ranking')

@section('content')
<div class="space-y-4">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('leaderboard', ['server' => $s->slug, 'map' => $map, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-lg font-semibold">
            Ranking {{ $map ? '— '.\App\Support\MapCatalog::mapLabel($map) : 'general' }}
        </h1>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'ranking-season-dropdown',
            'seasonBaseRoute' => 'leaderboard',
            'seasonBaseParams' => ['server' => $server?->slug, 'map' => $map],
        ])
    </div>

    <div class="flex items-center gap-2 text-sm flex-wrap">
        <a href="{{ route('leaderboard', ['server' => $server?->slug, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg border {{ !$map ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">General</a>
        @foreach($mapGroups as $mapCode => $group)
            <a href="{{ route('leaderboard', ['server' => $server?->slug, 'map' => $mapCode, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg border {{ $map === $mapCode ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ \App\Support\MapCatalog::mapLabel($mapCode) }}</a>
        @endforeach
    </div>

    @if($map && ($mapGroups[$map]->dates ?? collect())->count() > 1)
        <div class="flex items-center gap-2 text-xs -mt-2 flex-wrap">
            <span class="text-slate-500 uppercase tracking-wide">{{ $mapGroups[$map]->dates->count() }} sesiones en esta temporada</span>
        </div>
    @endif

    @php
        // El detalle de kills/fuego amigo filtra por codigo de mapa EXACTO
        // (rounds.map), que nunca es el codigo normalizado ($map, ej. mp_dawnville)
        // sino la variante real (mp_dawnville_fix/mp_dawnville_sun) — hay que mandar
        // $mapCodes (las variantes que arma esta pestaña) o el filtro no encuentra
        // ninguna ronda.
        $tkParams = http_build_query(array_filter([
            'server' => $server?->slug,
            'map' => $mapCodes ? implode(',', $mapCodes) : null,
            'season' => $seasonId,
        ]));
    @endphp
    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">#</th>
                    <th class="px-4 py-2 font-medium">Jugador</th>
                    <th class="px-4 py-2 font-medium text-right">Kills</th>
                    <th class="px-4 py-2 font-medium text-right">Muertes</th>
                    <th class="px-4 py-2 font-medium text-right">K/D</th>
                    <th class="px-4 py-2 font-medium text-right">Headshots</th>
                    <th class="px-4 py-2 font-medium text-right">Granadas</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $i => $row)
                    @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                    <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                        <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                        <td class="px-4 py-2 font-medium">
                            @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                            <a href="{{ route('players.show', [$row->player->guid, 'season' => $seasonId]) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                            @if($i < 3)
                                <span class="ml-1 align-text-bottom" title="{{ match($i) { 0 => 'Oro', 1 => 'Plata', 2 => 'Bronce' } }}">{{ match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉' } }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                            <span class="relative inline-block">
                                <button type="button" data-kills-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->kills }}</button>
                                @if($row->teamkills > 0)
                                    <button type="button" data-teamkill-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $row->teamkills }})</button>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $row->deaths }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $row->headshots }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $row->grenade_kills }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Sin datos para esta temporada.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($axisRows->isNotEmpty() || $alliesRows->isNotEmpty())
        <div>
            <h2 class="text-sm uppercase tracking-wide text-slate-200 font-bold mb-3">Tabla de Posiciones</h2>
            <div class="grid md:grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-800 text-xs uppercase tracking-wide text-red-400 font-medium flex items-center gap-2">
                        Axis
                        @if($sideScores['axis'] !== null)
                            <span class="text-slate-400 normal-case">({{ $sideScores['axis'] }})</span>
                        @endif
                        @if($sideScores['winning'] === 'axis')
                            <span class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-900 text-[10px] normal-case tracking-normal">Ganador</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 font-medium">Jugador</th>
                                <th class="px-4 py-2 font-medium text-right">Kills</th>
                                <th class="px-4 py-2 font-medium text-right">Muertes</th>
                                <th class="px-4 py-2 font-medium text-right">K/D</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($axisRows->sortByDesc('kills') as $row)
                                @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                                <tr class="border-b border-slate-800/60 last:border-0">
                                    <td class="px-4 py-2 font-medium">
                                        @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                        <a href="{{ route('players.show', [$row->player->guid, 'season' => $seasonId]) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                        <span class="relative inline-block">
                                            <button type="button" data-kills-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->kills }}</button>
                                            @if($row->teamkills > 0)
                                                <button type="button" data-teamkill-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $row->teamkills }})</button>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $row->deaths }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">Sin datos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-800 text-xs uppercase tracking-wide text-blue-400 font-medium flex items-center gap-2">
                        Allies
                        @if($sideScores['allies'] !== null)
                            <span class="text-slate-400 normal-case">({{ $sideScores['allies'] }})</span>
                        @endif
                        @if($sideScores['winning'] === 'allies')
                            <span class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-900 text-[10px] normal-case tracking-normal">Ganador</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 font-medium">Jugador</th>
                                <th class="px-4 py-2 font-medium text-right">Kills</th>
                                <th class="px-4 py-2 font-medium text-right">Muertes</th>
                                <th class="px-4 py-2 font-medium text-right">K/D</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($alliesRows->sortByDesc('kills') as $row)
                                @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                                <tr class="border-b border-slate-800/60 last:border-0">
                                    <td class="px-4 py-2 font-medium">
                                        @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                        <a href="{{ route('players.show', [$row->player->guid, 'season' => $seasonId]) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                        <span class="relative inline-block">
                                            <button type="button" data-kills-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->kills }}</button>
                                            @if($row->teamkills > 0)
                                                <button type="button" data-teamkill-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $row->teamkills }})</button>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $row->deaths }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">Sin datos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
```

**Nota:** este reemplazo saca el form de filtro de fecha manual (`Desde`/`Hasta`) y el modal de date-pills por mes (ya no hace falta navegar por fecha — el selector de temporada lo reemplaza). El bloque "Tabla de Posiciones" (Axis/Allies) de arriba es una transcripción exacta del archivo original (`git show HEAD:resources/views/leaderboard.blade.php` antes de este task), con el único cambio de agregar `'season' => $seasonId` a los dos links `route('players.show', ...)` — todo lo demás (tablas, columnas K/D, popovers de kills/team-kills, banderas de país, colores rojo/azul de Axis/Allies) queda igual.

- [ ] **Step 3: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones — el Task 2 quedó con la vista rota (variables faltantes), este paso lo cierra. Mismo baseline que siempre (58 tests, 1 fallo preexistente).

- [ ] **Step 4: Commit**

```bash
git add resources/views/partials/season-selector.blade.php resources/views/leaderboard.blade.php
git commit -m "Selector de temporada en /ranking, sacar filtro de fecha manual"
```

---

### Task 4: `KillAggregator::aggregateByMap()` + `/jugadores/{guid}` scopeado por temporada (backend)

**Files:**
- Modify: `app/Support/KillAggregator.php`
- Modify: `app/Http/Controllers/PlayerController.php`
- Test: `tests/Feature/PlayerShowSeasonTest.php`

**Interfaces:**
- Consumes: `GameMatch::forSeason($seasonId)` (Task 2).
- Produces: `KillAggregator::aggregateByMap(Closure $baseQuery, int $playerId): Collection` — cada item: `{map: string, server: ?Server, kills: int, deaths: int, teamkills: int}`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerShowSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private Player $attacker;
    private Player $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);

        $this->attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $this->victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
    }

    private function realMatchWithKill(int $seasonId, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => $map,
                'gametype' => 'sd',
                'started_at' => now(),
                'ended_at' => now(),
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $this->attacker->id,
            'attacker_guid' => $this->attacker->guid,
            'attacker_name' => $this->attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $this->victim->id,
            'victim_guid' => $this->victim->guid,
            'victim_name' => $this->victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => true,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_profile_without_season_param_shows_only_the_active_season(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', $this->attacker->guid));

        $response->assertOk();
        $player = $response->viewData('player');
        $this->assertSame(2, $player->kills_total); // solo Temporada 2 (activa)
        $this->assertSame(0, $player->deaths_total); // el attacker no murio en esas partidas
        $this->assertSame(100.0, $player->headshot_rate); // getHeadshotRateAttribute() recalcula sobre kills_total/headshots_total ya scopeados -- los 2 kills de la fixture son headshot
    }

    public function test_profile_with_season_all_shows_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', [$this->attacker->guid, 'season' => 'all']));

        $player = $response->viewData('player');
        $this->assertSame(2, $player->kills_total);
    }

    public function test_profile_map_stats_are_scoped_to_the_season(): void
    {
        $season = Season::current();
        $this->realMatchWithKill($season->id, 'mp_toujane_fix');
        $this->realMatchWithKill($season->id, 'mp_railyard');

        $response = $this->get(route('players.show', $this->attacker->guid));

        $mapStats = $response->viewData('player')->mapStats;
        $this->assertSame(2, $mapStats->count());
        $this->assertSame(1, $mapStats->firstWhere('map', 'mp_toujane_fix')->kills);
    }

    public function test_profile_recent_kills_are_scoped_to_the_season(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', $this->attacker->guid));

        $this->assertSame(1, $response->viewData('recentKills')->count());
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `vendor/bin/phpunit tests/Feature/PlayerShowSeasonTest.php`
Expected: FAIL — hoy `PlayerController` lee `$player->kills_total` (de por vida), así que el primer test ve `3` en vez de `2`.

- [ ] **Step 3: Agregar `aggregateByMap()` a `KillAggregator`**

En `app/Support/KillAggregator.php`, agregar el método (dentro de la clase, después de `aggregate()`):

```php
    /**
     * Igual proposito que aggregate(), pero agrupado por mapa+servidor para UN
     * jugador especifico, en vez de por jugador para todos -- usado por el perfil
     * de jugador ("Mejores mapas"), donde antes se leia player_map_stats
     * (acumulado de por vida) y ahora se calcula al vuelo scopeado por temporada.
     *
     * @param  Closure(): \Illuminate\Database\Eloquent\Builder  $baseQuery  Query de
     *      Kill ya scopeada (season/gametype/etc), SIN filtrar por jugador todavia.
     */
    public static function aggregateByMap(Closure $baseQuery, int $playerId): Collection
    {
        $kills = $baseQuery()->where('kills.attacker_player_id', $playerId)->where('kills.is_suicide', false)
            ->selectRaw('rounds.map as map, rounds.server_id as server_id, count(*) as kills, sum(kills.is_teamkill) as teamkills')
            ->groupBy('rounds.map', 'rounds.server_id')
            ->get();

        $deaths = $baseQuery()->where('kills.victim_player_id', $playerId)
            ->selectRaw('rounds.map as map, rounds.server_id as server_id, count(*) as deaths')
            ->groupBy('rounds.map', 'rounds.server_id')
            ->get();

        $key = fn ($row) => $row->map.'|'.$row->server_id;
        $killsByKey = $kills->keyBy($key);
        $deathsByKey = $deaths->keyBy($key);

        $allKeys = $killsByKey->keys()->merge($deathsByKey->keys())->unique();
        $serverIds = $allKeys->map(fn ($k) => (int) explode('|', $k)[1])->unique();
        $servers = \App\Models\Server::whereIn('id', $serverIds)->get()->keyBy('id');

        return $allKeys->map(function ($mapKey) use ($killsByKey, $deathsByKey, $servers) {
            [$map, $serverId] = explode('|', $mapKey);
            $k = $killsByKey->get($mapKey);
            $d = $deathsByKey->get($mapKey);

            return (object) [
                'map' => $map,
                'map_codes' => [$map],
                'server' => $servers->get((int) $serverId),
                'kills' => (int) ($k->kills ?? 0),
                'deaths' => (int) ($d->deaths ?? 0),
                'teamkills' => (int) ($k->teamkills ?? 0),
            ];
        })->sortByDesc('kills')->values();
    }
```

- [ ] **Step 4: Reescribir `PlayerController`**

Reemplazar el archivo completo `app/Http/Controllers/PlayerController.php` por:

```php
<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerWeaponPick;
use App\Models\Season;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerController extends Controller
{
    public function show(Request $request, Player $player)
    {
        $player->load(['aliases' => fn ($q) => $q->orderByDesc('last_seen_at')]);

        $seasons = Season::orderByDesc('started_at')->get();
        $seasonParam = $request->query('season');
        $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        $baseKillQuery = fn () => Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.gametype', 'sd')
            ->whereIn('kills.match_id', $matchIds);

        // Numeros principales — antes players.kills_total/etc (de por vida), ahora
        // calculados al vuelo scopeados a la temporada elegida. aggregate() ya trae
        // kills/deaths/headshots/grenade_kills/teamkills/bash agrupado por jugador;
        // se scopea la query base a este jugador solo (attacker O victim) antes de
        // llamarlo, para no calcular el ranking completo del server solo para leer
        // una fila.
        $totals = KillAggregator::aggregate(fn () => $baseKillQuery()
            ->where(fn ($q) => $q->where('kills.attacker_player_id', $player->id)->orWhere('kills.victim_player_id', $player->id))
        )->firstWhere('player.id', $player->id);

        // Overriding these in-memory (not saved) lets the existing view/accessors
        // (Player::getKdRatioAttribute()/getHeadshotRateAttribute(), which read
        // $this->kills_total/deaths_total/headshots_total) work unchanged against
        // the season-scoped numbers instead of the lifetime columns.
        $player->kills_total = $totals->kills ?? 0;
        $player->deaths_total = $totals->deaths ?? 0;
        $player->headshots_total = $totals->headshots ?? 0;
        $player->grenade_kills_total = $totals->grenade_kills ?? 0;

        $mapStats = KillAggregator::aggregateByMap($baseKillQuery, $player->id)
            ->filter(fn ($s) => $s->kills > 0 || $s->deaths > 0);
        $player->setRelation('mapStats', MapCatalog::mergeVariants($mapStats));

        $recentKills = $player->kills()->where('is_suicide', false)->whereIn('match_id', $matchIds)
            ->with('round', 'victim')->latest('id')->limit(15)->get();
        $recentDeaths = $player->deaths()->whereIn('match_id', $matchIds)
            ->with('round', 'attacker')->latest('id')->limit(15)->get();

        // Scoped to SD like the rest of the ranking (kills_total etc.) — a DM/HQ/CTF
        // kill shouldn't skew "favorite weapon" or the team-kill count.
        $favoriteWeapon = $baseKillQuery()
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_suicide', false)
            ->select('kills.weapon', DB::raw('count(*) as uses'))
            ->groupBy('kills.weapon')
            ->orderByDesc('uses')
            ->first();

        // Included in kills_total (zPAM's own Score counts it too, confirmed against a
        // real match) — this is just for visibility, not a separate/excluded number.
        $teamkillCount = $baseKillQuery()
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_teamkill', true)
            ->count();

        $mostEquippedWeapon = PlayerWeaponPick::where('player_id', $player->id)
            ->when($seasonId !== 'all', fn ($q) => $q->where('season_id', $seasonId))
            ->orderByDesc('picks')
            ->first();

        return view('players.show', compact('player', 'seasons', 'seasonId', 'recentKills', 'recentDeaths', 'favoriteWeapon', 'teamkillCount', 'mostEquippedWeapon'));
    }
}
```

**Nota:** `PlayerController::show()` ahora tiene la firma `show(Request $request, Player $player)` — antes era `show(Player $player)`. Confirmar que la ruta (`Route::get('/jugadores/{player:guid}', [PlayerController::class, 'show'])`) sigue funcionando igual (Laravel inyecta `Request` automáticamente sin cambios en la ruta).

**Nota sobre "arma más equipada" con `season=all`:** cuando se elige "Todo el historial", `$mostEquippedWeapon` toma el pick con más `picks` **de cualquier temporada individual** (no la suma entre temporadas — sumar entre filas de distintas temporadas para la misma arma no está en el alcance de este sub-proyecto, es un detalle menor aceptable dado que "todo el historial" ya es la vista de excepción, no el default).

- [ ] **Step 5: Correr el test y confirmar que pasa**

Run: `vendor/bin/phpunit tests/Feature/PlayerShowSeasonTest.php`
Expected: PASS (4 tests) — `test_profile_without_season_param_shows_only_the_active_season` verifica `kills_total`, `deaths_total` y `headshot_rate` directamente contra los valores esperados de la fixture (ver Step 4 más arriba); no hay ambigüedad que resolver acá.

- [ ] **Step 6: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones nuevas (la vista `players/show.blade.php` todavía no conoce `$seasons`/`$seasonId` — se resuelve en el Task 5, mismo patrón que el Task 2→3).

- [ ] **Step 7: Commit**

```bash
git add app/Support/KillAggregator.php app/Http/Controllers/PlayerController.php tests/Feature/PlayerShowSeasonTest.php
git commit -m "Scopear el perfil de jugador por temporada (backend)"
```

---

### Task 5: `/jugadores/{guid}` — vista con selector de temporada

**Files:**
- Modify: `resources/views/players/show.blade.php`

**Interfaces:**
- Consumes: `partials.season-selector` (Task 3), `$seasons`/`$seasonId` (Task 4).

- [ ] **Step 1: Modificar `players/show.blade.php`**

En `resources/views/players/show.blade.php`, agregar el selector de temporada junto al título (reemplazar el bloque de las líneas 6-9):

```blade
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-xl font-semibold">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</h1>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'player-season-dropdown',
            'seasonBaseRoute' => 'players.show',
            'seasonBaseParams' => [$player->guid],
        ])
    </div>
```

El resto del archivo no necesita cambios — `$player->kills_total`/`deaths_total`/`headshots_total`/`grenade_kills_total`/`kd_ratio`/`headshot_rate`, `$player->mapStats`, `$recentKills`, `$recentDeaths`, `$favoriteWeapon`, `$mostEquippedWeapon`, `$teamkillCount` ya vienen scopeados por temporada desde el controller (Task 4), y el resto de la vista ya los consume tal cual.

- [ ] **Step 2: Correr toda la suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones — mismo baseline que siempre (61 tests si se suman los 3 tests nuevos de Task 1/2/4... el número exacto depende de cuántos tests trajo cada task anterior; lo que importa es "0 fallos nuevos, solo el `ExampleTest.php` preexistente").

- [ ] **Step 3: Commit**

```bash
git add resources/views/players/show.blade.php
git commit -m "Selector de temporada en el perfil de jugador"
```

---

### Task 6: Documentar, backup, y desplegar

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: nada nuevo — es el paso de cierre.

- [ ] **Step 1: Agregar una entrada en `CLAUDE.md`**

Documentar (mismo estilo que el resto de la bitácora, después de la sección "Temporadas — infraestructura base"): qué cambió (`/ranking` y `/jugadores/{guid}` ahora scopean por temporada, default = activa en todo el sitio), la decisión de calcular al vuelo en vez de una tabla nueva (y por qué, con referencia a los bugs de sincronización ya documentados), el fix del bug heredado de partidas abandonadas en `aggregateFromKills()`, y que `/especialidades` sigue sin temporada (sub-proyecto 3). Link a la spec:
`docs/superpowers/specs/2026-08-25-ranking-por-temporada-design.md`.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "Documentar el ranking y perfil de jugador por temporada"
```

- [ ] **Step 3: Backup de producción ANTES de desplegar**

```bash
ssh cod2-vps-new "cd /var/www/cod2.4livepro.com && php artisan backup:run"
TS=$(date +%Y-%m-%d_%H%M%S)
ssh cod2-vps-new "mkdir -p /root/backups/pre-ranking-temporada && tar -czf /root/backups/pre-ranking-temporada/cod2-ranking-app_${TS}.tar.gz -C /var/www cod2.4livepro.com"
```

- [ ] **Step 4: Verificación manual de la migración contra una copia real de producción**

Mismo procedimiento que el sub-proyecto 1 (Task 1, Step 9 de ese plan): restaurar el dump más reciente de `storage/app/private/backups/` a una base `cod2_dryrun` temporal en el VPS, apuntar un clon descartable ahí, correr `php artisan migrate --force`, y confirmar:

```sql
SELECT COUNT(*) FROM player_weapon_picks WHERE season_id IS NULL; -- debe ser 0
```

Contra el conteo de filas de `player_weapon_picks` antes/después (debe coincidir, la migración no borra ninguna). Limpiar la base y el usuario temporal al terminar.

- [ ] **Step 5: Desplegar**

```bash
git archive HEAD | ssh cod2-vps-new "tar -x -C /var/www/cod2.4livepro.com"
ssh cod2-vps-new "chown -R www-data:www-data /var/www/cod2.4livepro.com && chmod +x /var/www/cod2.4livepro.com/artisan"
ssh cod2-vps-new "cd /var/www/cod2.4livepro.com && php artisan migrate --force && php artisan optimize"
ssh cod2-vps-new "chown -R www-data:www-data /var/www/cod2.4livepro.com"
```

- [ ] **Step 6: Verificar en producción**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://cod2.4livepro.com/ranking
curl -s -o /dev/null -w '%{http_code}\n' "https://cod2.4livepro.com/ranking?season=all"
```

Ambos deben dar `200`. Confirmar visualmente (o con `curl` + grep del HTML) que el selector de temporada aparece y que el ranking sin parámetros muestra números distintos a `?season=all` si hay más de una temporada con datos reales.

- [ ] **Step 7: Rollback, si algo falla**

Re-desplegar el commit anterior al merge de este sub-proyecto:

```bash
git archive <commit-anterior-al-merge> | ssh cod2-vps-new "tar -x -C /var/www/cod2.4livepro.com"
ssh cod2-vps-new "chown -R www-data:www-data /var/www/cod2.4livepro.com && cd /var/www/cod2.4livepro.com && php artisan optimize && chown -R www-data:www-data /var/www/cod2.4livepro.com"
```

No hace falta revertir la migración (`player_weapon_picks.season_id` es aditiva, no rompe nada mientras el código viejo no la lea) — solo re-desplegar el código anterior alcanza.
