# Especialidades por temporada (Sub-proyecto 3 de 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que 22 de las 25 páginas de `/especialidades` respeten la temporada elegida (activa por defecto, cualquier cerrada, o "Todo el historial"), igual que ya hacen `/ranking` y `/jugadores/{guid}` desde el sub-proyecto 2. `bombs`, `damage`, `disconnects` quedan fuera de alcance (ver spec).

**Spec:** [docs/superpowers/specs/2026-08-26-especialidades-por-temporada-design.md](../specs/2026-08-26-especialidades-por-temporada-design.md)

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + Tailwind (CDN), MySQL en producción / SQLite en memoria para tests.

## Global Constraints

- Default en TODO `/especialidades`: la temporada activa. `?season={id}` para una cerrada, `?season=all` para el histórico completo (mismo contrato de URL que `/ranking`).
- `bombs`, `damage`, `disconnects` NO se tocan en este plan — quedan mostrando el histórico completo, sin selector.
- `player_server_stats`, `player_map_stats`, `players.*_total` no se tocan ni se borran — dejan de leerse desde las páginas que las reemplazan, nada más.
- Sin tabla de acumuladores nueva — todo se calcula al vuelo con `whereIn(..., $matchIds)`, reusando `GameMatch::scopeForSeason()` (ya existe).
- El selector de temporada es el partial reusable `partials.season-selector` (ya existe, del sub-proyecto 2) — mismos parámetros (`seasonDropdownId`, `seasonBaseRoute`, `seasonBaseParams`, más `$seasons`/`$seasonId` heredados del controller).
- `PlayerRankCalculator` (usado por `rango()` Y por el balanceador de Equipos) también pasa a usar la temporada activa — Equipos no tiene selector propio, siempre la activa.

---

### Task 1: `SpecialtyController::resolveSeason()` + `sdKills()` con `$matchIds`

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`

**Interfaces:**
- Consumes: `Season::current()`, `GameMatch::forSeason()` (ya existen, del sub-proyecto 2).
- Produces: `SpecialtyController::resolveSeason(Request $request): array` — `[$seasons, $seasonId, $matchIds]`, mismo contrato que ya usan `LeaderboardController`/`PlayerController` inline. `SpecialtyController::sdKills(int $serverId, $matchIds)` — firma nueva, agrega `whereIn('kills.match_id', $matchIds)`.

- [ ] **Step 1: Agregar el helper y los imports**

En `app/Http/Controllers/SpecialtyController.php`, agregar `use App\Models\Season;` a los imports (ya tiene `GameMatch`), y el método privado (junto a `resolveServer()`):

```php
/** @return array{0: \Illuminate\Support\Collection, 1: int|string, 2: \Illuminate\Support\Collection} */
private function resolveSeason(Request $request): array
{
    $seasons = Season::orderByDesc('started_at')->get();
    $seasonParam = $request->query('season');
    $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
    $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

    return [$seasons, $seasonId, $matchIds];
}
```

- [ ] **Step 2: Cambiar la firma de `sdKills()`**

```php
private function sdKills(int $serverId, $matchIds)
{
    return Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
        ->where('rounds.server_id', $serverId)
        ->where('rounds.gametype', 'sd')
        ->where('kills.is_suicide', false)
        ->whereIn('kills.match_id', $matchIds);
}
```

**No actualices las llamadas existentes a `sdKills()` todavía** — eso rompe deliberadamente la compilación de los métodos que la usan (Task 4 los arregla). Confirmar con `grep -n "sdKills(" app/Http/Controllers/SpecialtyController.php` cuántos call-sites hay (deberían seguir con la firma vieja de un solo argumento hasta que su Task correspondiente los toque).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php
git commit -m "Agregar SpecialtyController::resolveSeason() y matchIds a sdKills()"
```

**Nota para el controlador del plan:** este Task deja el archivo sin compilar del todo bien (las llamadas viejas a `sdKills($serverId)` con 1 argumento van a fallar en PHP 8 con "Too few arguments"). Es intencional — cada Task siguiente que toca un método que llama a `sdKills()` lo actualiza a la nueva firma como parte de su propio cambio. **No se puede desplegar nada de este plan hasta que TODOS los métodos que llaman a `sdKills()` estén actualizados** (Tasks 2 y 4). Si hace falta correr la suite completa entre tasks intermedias, un test que ejercite un método todavía no migrado va a fallar por este motivo — es esperable, no una regresión real, hasta que el plan esté completo.

---

### Task 2: Grupo A — reescribir `grenades`, `headshots`, `friendlyFire`, `efficiency`, `bashCalls` sobre `KillAggregator::aggregate()`

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/GroupASeasonTest.php`

**Interfaces:**
- Consumes: `KillAggregator::aggregate(Closure $baseQuery): Collection` (ya existe — devuelve `{player, kills, headshots, grenade_kills, teamkills, bash, deaths}` por jugador), `resolveSeason()` (Task 1).

Estos 5 métodos hoy leen una columna de `PlayerServerStat` (acumulador de por vida, no scopeable por temporada). Se reemplazan por `KillAggregator::aggregate()` scopeado a `$matchIds`, tomando la columna equivalente de cada fila del resultado.

- [ ] **Step 1: Escribir los tests que fallan**

Un test por método, contra una fixture con 2 temporadas (mismo patrón que `LeaderboardSeasonTest`/`PlayerShowSeasonTest`: crear un `GameMatch` con 13 rondas + kills reales en la temporada vieja, cerrar la temporada, crear otra, otro match con kills en la nueva). Verificar que sin `?season=` el total mostrado es SOLO el de la temporada activa, y que `?season=all` suma ambas. Ejemplo para `grenades` (los otros 4 siguen el mismo patrón, cambiando la columna/atributo relevante):

```php
public function test_grenades_excludes_old_season_kills(): void
{
    $oldSeason = Season::current();
    $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
    $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

    // partida vieja: 1 kill con granada
    $this->realMatchWithKill($oldSeason->id, $attacker, $victim, isGrenade: true);

    $oldSeason->update(['ended_at' => now()]);
    $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
    // partida nueva: 2 kills con granada
    $this->realMatchWithKill($newSeason->id, $attacker, $victim, isGrenade: true);
    $this->realMatchWithKill($newSeason->id, $attacker, $victim, isGrenade: true);

    $response = $this->get(route('specialties.grenades', ['server' => $this->server->slug]));

    $response->assertOk();
    $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
    $this->assertSame(2, $row->value); // solo la temporada activa

    $responseAll = $this->get(route('specialties.grenades', ['server' => $this->server->slug, 'season' => 'all']));
    $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
    $this->assertSame(3, $rowAll->value); // las 2 temporadas
}
```

Escribir un helper `realMatchWithKill()` en el test (mismo patrón que `LeaderboardSeasonTest`/`PlayerShowSeasonTest` — crear `GameMatch` con 13 rondas para que cuente como concluida, más el `Kill` con los flags que pida cada caso: `is_grenade`, `is_headshot`, `is_teamkill`, o ninguno para el caso de `efficiency`/K-D). `efficiency` necesita además una fixture con `>= 20` kills totales (su `$minKills` actual) para aparecer en el ranking — usar un loop de kills en vez de uno solo. `bashCalls` necesita `mod = 'MOD_MELEE'`.

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Expected: FAIL — los 5 métodos hoy leen `PlayerServerStat` (de por vida), sin filtro de temporada.

- [ ] **Step 3: Reescribir los 5 métodos**

Patrón para `grenades()` (los otros 4 análogos, cambiando la columna leída del resultado de `aggregate()` y el cálculo del stat card):

```php
public function grenades(Request $request)
{
    [$servers, $server] = $this->resolveServer($request);
    [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

    $rows = collect();
    $totalGrenadeKills = 0;
    $totalKills = 0;
    $favoriteGrenade = null;

    if ($server) {
        $all = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds));

        $rows = $all->filter(fn ($row) => $row->grenade_kills > 0)
            ->map(function ($row) {
                $row->value = $row->grenade_kills;
                $row->share = $row->kills > 0 ? round($row->grenade_kills / $row->kills * 100, 1) : 0;

                return $row;
            })
            ->sortByDesc('value')->take(50)->values();

        $totalGrenadeKills = $all->sum('grenade_kills');
        $totalKills = $all->sum('kills');

        $favoriteGrenade = $this->sdKills($server->id, $matchIds)
            ->where('kills.is_grenade', true)
            ->selectRaw('kills.weapon, count(*) as uses')
            ->groupBy('kills.weapon')
            ->orderByDesc('uses')
            ->first();
    }

    return view('specialties.ranking', [
        'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
        // ... resto de los parametros de vista IDENTICO al metodo actual, sin cambios
    ]);
}
```

**Ojo con `->player` en las filas de `aggregate()`:** ya viene resuelto (`$row->player`), a diferencia del código viejo que usaba `->with('player')` de Eloquent — no hace falta ningún cambio en la vista para esto, el shape del objeto (`player`, `value`, `share`) es el mismo que ya consume `specialties/ranking.blade.php`.

Para `friendlyFire`: `$row->teamkills` en vez de `grenade_kills`, sin `favoriteGrenade`. Para `headshots`: `$row->headshots`. Para `efficiency`: `$row->kills >= $minKills` como filtro (en vez de `$row->kills > 0`), `value = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills` (mismo cálculo de K/D que ya existe en `Player::getKdRatioAttribute()`, transcripto igual acá). Para `bashCalls`: `$row->bash`, y el campo extra `'kills' => $row->kills` (reemplaza el `$stats[$row->player_id]->kills` que hoy viene de `PlayerServerStat`).

Cada uno de los 5 métodos agrega `'seasons' => $seasons, 'seasonId' => $seasonId` al array que le pasa a `view('specialties.ranking', [...])` — la vista los necesita para el selector (Task 9).

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Expected: PASS (5 tests, uno por método).

- [ ] **Step 5: Correr toda la suite**

Expected: los métodos NO tocados en este Task que ya llamaban a `sdKills($serverId)` con 1 argumento van a seguir fallando por la firma nueva del Task 1 — esperable hasta Task 4, no una regresión de este Task. Los tests de `LeaderboardSeasonTest`/`PlayerShowSeasonTest`/etc. del sub-proyecto 2 no deberían verse afectados (archivos distintos).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/GroupASeasonTest.php
git commit -m "Scopear por temporada: granadas, headshots, fuego amigo, eficiencia, bash"
```

---

### Task 3: Grupo B — reescribir `suicides`

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/SuicidesSeasonTest.php`

**Interfaces:**
- Consumes: `resolveSeason()` (Task 1).

`suicides()` hoy lee `PlayerServerStat.suicides` (de por vida). No hay contraparte directa en `KillAggregator::aggregate()` (que excluye suicidios a propósito, `where('kills.is_suicide', false)`) — se reescribe como una query en vivo separada.

- [ ] **Step 1: Escribir el test que falla**

Mismo patrón que Task 2 (2 temporadas, kill con `is_suicide: true`, verificar exclusión sin `season` y suma con `season=all`).

- [ ] **Step 2: Correr el test y confirmar que falla**

- [ ] **Step 3: Reescribir el método**

```php
public function suicides(Request $request)
{
    [$servers, $server] = $this->resolveServer($request);
    [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

    $rows = collect();
    $totalSuicides = 0;

    if ($server) {
        $counts = Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.server_id', $server->id)
            ->where('kills.is_suicide', true)
            ->whereNotNull('kills.attacker_player_id')
            ->whereIn('kills.match_id', $matchIds)
            ->selectRaw('kills.attacker_player_id as player_id, count(*) as c')
            ->groupBy('kills.attacker_player_id')
            ->orderByDesc('c')
            ->limit(50)
            ->get();

        $players = Player::whereIn('id', $counts->pluck('player_id'))->get()->keyBy('id');

        $rows = $counts->map(function ($row) use ($players) {
            $player = $players[$row->player_id] ?? null;

            return $player ? (object) ['player' => $player, 'value' => $row->c, 'share' => null] : null;
        })->filter()->values();

        $totalSuicides = (int) $counts->sum('c');
    }

    return view('specialties.ranking', [
        'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
        // ... resto identico al metodo actual
    ]);
}
```

**Nota:** `Kill.is_suicide` no filtra por `gametype=sd` como `sdKills()` — el método actual tampoco lo hacía (leía `PlayerServerStat.suicides`, que sí es solo-SD por cómo lo escribe `ParseCod2Log`). Agregar `->where('rounds.gametype', 'sd')` a la query de arriba para preservar ese comportamiento exacto.

- [ ] **Step 4: Correr el test y confirmar que pasa**

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/SuicidesSeasonTest.php
git commit -m "Scopear suicidios por temporada"
```

---

### Task 4: Grupo C — `grenadeDeaths`, `weapons`, `rivalries`, `recentActivity`, `peakTimes`

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/GroupCSeasonTest.php`

**Interfaces:**
- Consumes: `resolveSeason()`, `sdKills($serverId, $matchIds)` (Task 1).

Estos 5 métodos YA son queries en vivo contra `kills`/`rounds` vía `sdKills()` — el cambio es mecánico: agregar `[$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);` al principio de cada uno, y pasar `$matchIds` como segundo argumento en cada llamada a `$this->sdKills($server->id)` → `$this->sdKills($server->id, $matchIds)`. Agregar `'seasons' => $seasons, 'seasonId' => $seasonId` al array de cada `view(...)`.

**Excepción — `grenadeDeaths` y (de Task 2) `bashCalls` leen una columna `kills` de referencia desde `PlayerServerStat`:**
en `grenadeDeaths()`, reemplazar el bloque que arma `$stats = PlayerServerStat::where(...)->whereIn('player_id', $counts->pluck('player_id'))->get()->keyBy('player_id');` y `'kills' => $stats[$row->player_id]->kills ?? null` por un conteo en vivo equivalente — la forma más simple es un segundo query chico: `$totalsByPlayer = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds))->keyBy('player.id');` y usar `$totalsByPlayer[$row->player_id]->kills ?? null`.

**Excepción — `weapons`:** no usa `PlayerServerStat` para nada relevante a la temporada (solo `Player::whereIn('id', ...)` para nombres), así que es puramente mecánico.

**Excepción — `rivalries`:** ídem, puramente mecánico (todas sus queries ya van por `sdKills()`).

**Excepción — `peakTimes`:** ídem, puramente mecánico (las 2 queries van por `sdKills()`).

**Excepción — `recentActivity`:** esta página filtra "últimos 7 días" (`$since = now()->subDays(7)`) — eso ya es, de hecho, un filtro de fecha independiente del de temporada. Con el filtro de temporada agregado ENCIMA (`whereIn('kills.match_id', $matchIds)`), una temporada cerrada hace más de 7 días mostraría la página vacía siempre — comportamiento correcto y esperado (no hay "actividad reciente" de una temporada que ya terminó hace tiempo), no hace falta ningún caso especial.

- [ ] **Step 1: Escribir los tests que fallan**

Un test por método (5), mismo patrón de fixture de 2 temporadas que Tasks 2/3. Para `recentActivity`, usar `now()` para ambos kills (temporada vieja y nueva) ya que el filtro de "7 días" es independiente del de temporada — el punto a probar es solo que la temporada vieja no aporta, no la ventana de fecha.

- [ ] **Step 2: Correr los tests y confirmar que fallan**

- [ ] **Step 3: Aplicar los cambios mecánicos + las 2 excepciones (`grenadeDeaths`)**

- [ ] **Step 4: Correr los tests y confirmar que pasan**

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/GroupCSeasonTest.php
git commit -m "Scopear por temporada: muertes por nade, armas, rivalidades, actividad reciente, hora pico"
```

---

### Task 5: Grupo D — `mapsWon`, `streaks`, `winRate`, `rango`, `playtime`, `clutches`

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/GroupDSeasonTest.php`

**Interfaces:**
- Consumes: `resolveSeason()` (Task 1).

Estos 6 métodos ya arman su lista de partidas/rondas candidatas directo desde `GameMatch`/`Round` (no vía `sdKills()`) — el cambio es agregar `->whereIn('id', $matchIds)` (para queries sobre `GameMatch`) o `->whereIn('match_id', $matchIds)` (para queries sobre `Round`, que ya tiene esa columna directo) a la query inicial de cada método, más `[$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);` al principio y `'seasons'/'seasonId'` en cada `view(...)`.

- `mapsWon`: `GameMatch::where('server_id', ...)->where(...)->whereNotNull('ended_at')` → agregar `->whereIn('id', $matchIds)`.
- `streaks`: misma query base que `mapsWon`, mismo cambio.
- `winRate`: misma query base, mismo cambio.
- `rango`: misma query base (`$matches = GameMatch::where(...)`), mismo cambio. **Ojo:** `rango()` también arma `$stats = PlayerServerStat::with('player')->where('server_id', $server->id)->where('kills', '>=', $minKills)->...` — esto SÍ necesita reescritura (mismo patrón que Grupo A, `KillAggregator::aggregate()` en vez de `PlayerServerStat`) porque el K/D y headshots%/nade% que usa vienen de ahí. Ver el Step 3 de este Task para el detalle completo de `rango()`.
- `playtime`: la query base es sobre `Round` (`Round::where('server_id', $server->id)->where('gametype', 'sd')->whereNotNull('ended_at')`) — agregar `->whereIn('match_id', $matchIds)`.
- `clutches`: la query base también es sobre `Round` — agregar `->whereIn('match_id', $matchIds)` junto al `whereHas('match', ...)` que ya tiene.

- [ ] **Step 1: Escribir los tests que fallan**

Un test por método (6). Para `mapsWon`/`streaks`/`winRate`/`rango`, la fixture necesita una partida COMPLETA (13 rondas con `winner_guids` reales, no solo un kill suelto) en cada temporada, ya que estos métodos dependen de `TeamSideAnalyzer::winningRosterGuids()`. Reusar el helper de partida completa que ya existe en `LeaderboardSeasonTest`/`PlayerShowSeasonTest` (`realMatch()` con las 13 rondas) como referencia — puede que haga falta extenderlo para setear `winner_guids` reales por ronda si el helper actual no lo hace.

- [ ] **Step 2: Correr los tests y confirmar que fallan**

- [ ] **Step 3: Aplicar los cambios**

Para `rango()` específicamente, reemplazar:

```php
$stats = PlayerServerStat::with('player')
    ->where('server_id', $server->id)
    ->where('kills', '>=', $minKills)
    ->whereHas('player')
    ->get()
    ->keyBy('player.guid');
```

por:

```php
$statsRows = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds))
    ->filter(fn ($row) => $row->kills >= $minKills);

$stats = $statsRows->keyBy(fn ($row) => $row->player->guid);
```

Y ajustar las 3 líneas que leen `$stat->deaths`/`$stat->headshots`/`$stat->grenade_kills`/`$stat->kills` más abajo (`$qualified->push((object) [...])`) — el shape de `KillAggregator::aggregate()` (`kills`, `deaths`, `headshots`, `grenade_kills`, `player`) ya coincide exactamente con lo que esas líneas leen de `$stat`, así que no debería hacer falta tocarlas, solo confirmar que compilan igual contra el nuevo `$stats`.

- [ ] **Step 4: Correr los tests y confirmar que pasan**

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/GroupDSeasonTest.php
git commit -m "Scopear por temporada: mapas ganados, rachas, win-rate, rangos, horas jugadas, clutches"
```

---

### Task 6: Grupo E — `mapKings` (reescritura desde `PlayerMapStat`)

**Files:**
- Modify: `app/Support/KillAggregator.php` (nuevo método `topByMap()`)
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/MapKingsSeasonTest.php`

**Interfaces:**
- Produces: `KillAggregator::topByMap(Closure $baseQuery): Collection` — un item por mapa (código crudo, sin fusionar variantes — mismo criterio que ya tiene `mapKings()` hoy, ver su comentario existente), con el total de kills de ese mapa y el jugador con más kills en él.

- [ ] **Step 1: Escribir el test que falla**

Fixture: 2 temporadas, cada una con una partida en el mismo mapa (`mp_toujane_fix`) con kills de distintos jugadores — verificar que sin `?season=` el "rey" y el total de kills de ese mapa reflejan solo la temporada activa.

- [ ] **Step 2: Correr el test y confirmar que falla**

- [ ] **Step 3: Agregar `KillAggregator::topByMap()`**

```php
/**
 * Un item por mapa (codigo CRUDO, sin fusionar variantes -- mismo criterio que ya
 * usaba mapKings() con PlayerMapStat, a proposito: mp_dawnville_fix y
 * mp_dawnville_sun quedan separados acá), con el total de kills de ese mapa y el
 * jugador con mas kills en el.
 */
public static function topByMap(Closure $baseQuery): Collection
{
    $totals = $baseQuery()
        ->selectRaw('rounds.map as map, count(*) as uses')
        ->groupBy('rounds.map')
        ->get();

    $byPlayer = $baseQuery()
        ->whereNotNull('kills.attacker_player_id')
        ->selectRaw('rounds.map as map, kills.attacker_player_id, count(*) as kills, sum(kills.is_suicide) as deaths_placeholder')
        ->groupBy('rounds.map', 'kills.attacker_player_id')
        ->get()
        ->groupBy('map')
        ->map(fn ($rows) => $rows->sortByDesc('kills')->first());

    // Muertes del jugador top en ese mapa especifico -- segunda pasada chica, solo
    // para los (mapa, jugador) que ya salieron ganadores arriba.
    $topPairs = $byPlayer->values();
    $deathsByPair = $baseQuery()
        ->whereNotNull('kills.victim_player_id')
        ->selectRaw('rounds.map as map, kills.victim_player_id as attacker_player_id, count(*) as deaths')
        ->groupBy('rounds.map', 'kills.victim_player_id')
        ->get()
        ->keyBy(fn ($r) => $r->map.'|'.$r->attacker_player_id);

    $playerIds = $byPlayer->pluck('attacker_player_id')->filter()->unique();
    $players = Player::whereIn('id', $playerIds)->get()->keyBy('id');

    return $totals->map(function ($row) use ($byPlayer, $deathsByPair, $players) {
        $top = $byPlayer->get($row->map);
        $topPlayer = $top ? ($players[$top->attacker_player_id] ?? null) : null;
        $topDeaths = $top ? ($deathsByPair->get($row->map.'|'.$top->attacker_player_id)->deaths ?? 0) : 0;

        return (object) [
            'map' => $row->map,
            'uses' => $row->uses,
            'topPlayer' => $topPlayer,
            'topKills' => $top->kills ?? 0,
            'topDeaths' => $topDeaths,
        ];
    })->filter(fn ($m) => $m->topPlayer && $m->uses > 0)->values();
}
```

(Nota: `deaths_placeholder` en el primer `selectRaw` de `$byPlayer` no se usa, se puede sacar — quedó de una iteración intermedia al escribir este método, revisar antes de comitear.)

- [ ] **Step 4: Reescribir `mapKings()`**

```php
public function mapKings(Request $request)
{
    [$servers, $server] = $this->resolveServer($request);
    [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

    $maps = collect();

    if ($server) {
        $maps = KillAggregator::topByMap(fn () => $this->sdKills($server->id, $matchIds))
            ->map(fn ($m) => tap($m, fn ($m) => $m->mapLabel = \App\Support\MapCatalog::mapLabel($m->map)));
    }

    return view('specialties.map-kings', compact('servers', 'server', 'seasons', 'seasonId', 'maps'));
}
```

- [ ] **Step 5: Correr el test y confirmar que pasa**

- [ ] **Step 6: Commit**

```bash
git add app/Support/KillAggregator.php app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/MapKingsSeasonTest.php
git commit -m "Scopear reyes de mapa por temporada (KillAggregator::topByMap nuevo)"
```

---

### Task 7: Grupo F — `chattiest`, `timeouts`

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/GroupFSeasonTest.php`

**Interfaces:**
- Consumes: `resolveSeason()` (Task 1).

Ambas tablas (`chat_messages`, `match_events`) ya tienen `match_id` directo — agregar `->whereIn('match_id', $matchIds)` a sus queries y `[$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);` + `'seasons'/'seasonId'` en la vista, igual que los grupos anteriores.

- [ ] **Step 1: Escribir los tests que fallan**

Fixture: 2 temporadas, un `ChatMessage`/`MatchEvent` en cada una (con su `match_id` apuntando al `GameMatch` de esa temporada) — verificar exclusión sin `season` y suma con `season=all`.

- [ ] **Step 2: Correr los tests y confirmar que fallan**

- [ ] **Step 3: Aplicar los cambios**

- [ ] **Step 4: Correr los tests y confirmar que pasan**

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/GroupFSeasonTest.php
git commit -m "Scopear por temporada: mas hablador, timeouts"
```

---

### Task 8: Grupo G — `countries` (caso especial, sin selector de servidor)

**Files:**
- Modify: `app/Http/Controllers/SpecialtyController.php`
- Test: `tests/Feature/Specialties/CountriesSeasonTest.php`

**Interfaces:**
- Consumes: `resolveSeason()` (Task 1) — sin `resolveServer()` (esta página nunca tuvo selector de server, ver comentario ya existente en el método).

Pasa de "todos los jugadores con IP conocida, alguna vez" a "jugadores con al menos un kill o muerte dentro de `$matchIds` de la temporada elegida, agrupados por país" — unión de TODOS los servers activos (sigue sin selector de server).

- [ ] **Step 1: Escribir el test que falla**

Fixture: 2 jugadores con país conocido (IP seteada), uno con actividad solo en la temporada vieja, otro solo en la nueva — verificar que sin `?season=` solo aparece el de la temporada activa, y que `season=all` trae a los dos.

- [ ] **Step 2: Correr el test y confirmar que falla**

- [ ] **Step 3: Reescribir el método**

```php
public function countries(Request $request)
{
    [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

    // Sigue sin selector de server (ver comentario original) -- $matchIds ya viene
    // de GameMatch::forSeason(), que no filtra por server, asi que cubre todos los
    // servers activos de una.
    $activePlayerIds = Kill::whereIn('match_id', $matchIds)
        ->where(fn ($q) => $q->whereNotNull('attacker_player_id')->orWhereNotNull('victim_player_id'))
        ->get(['attacker_player_id', 'victim_player_id'])
        ->flatMap(fn ($k) => [$k->attacker_player_id, $k->victim_player_id])
        ->filter()
        ->unique();

    $players = Player::whereNotNull('ip')->whereIn('id', $activePlayerIds)
        ->get(['id', 'guid', 'last_name', 'last_name_plain', 'ip', 'kills_total']);

    // ... resto del metodo IDENTICO (el agrupamiento por pais no cambia, solo la
    // fuente de $players)

    return view('specialties.countries', [
        'countries' => $countries,
        'totalWithCountry' => $totalWithCountry,
        'totalPlayers' => $players->count(),
        'seasons' => $seasons,
        'seasonId' => $seasonId,
    ]);
}
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SpecialtyController.php tests/Feature/Specialties/CountriesSeasonTest.php
git commit -m "Scopear paises por temporada (solo jugadores activos en la temporada elegida)"
```

---

### Task 9: Vistas — agregar el selector de temporada a las 10 plantillas

**Files:**
- Modify: `resources/views/specialties/ranking.blade.php`
- Modify: `resources/views/specialties/weapons.blade.php`
- Modify: `resources/views/specialties/rivalries.blade.php`
- Modify: `resources/views/specialties/map-kings.blade.php`
- Modify: `resources/views/specialties/streaks.blade.php`
- Modify: `resources/views/specialties/countries.blade.php`
- Modify: `resources/views/specialties/peak-times.blade.php`
- Modify: `resources/views/specialties/timeouts.blade.php`
- Modify: `resources/views/specialties/win-rate.blade.php`
- Modify: `resources/views/specialties/rango.blade.php`

**Interfaces:**
- Consumes: `partials.season-selector` (ya existe, sub-proyecto 2), `$seasons`/`$seasonId` (Tasks 2-8 ya los pasan cada controller).

En cada una de las 10 vistas, ubicar el header (título de la página, `<h1>` o equivalente) y agregar el include al lado, mismo patrón que `leaderboard.blade.php`/`players/show.blade.php`:

```blade
@include('partials.season-selector', [
    'seasonDropdownId' => 'specialty-season-dropdown', // un id distinto por vista si hay mas de un selector en la misma pagina, si no un nombre generico alcanza
    'seasonBaseRoute' => $routeName ?? 'specialties.XXX', // usar la ruta real de esa pagina
    'seasonBaseParams' => ['server' => $server?->slug ?? null], // countries.blade.php no tiene $server, ver abajo
])
```

`specialties/ranking.blade.php` es la plantilla compartida por 16 páginas (Task 2, 3, 4 grupos A/B/C mayormente) — ya recibe `$routeName` como variable (usado en otras partes de la vista para armar links "activo"), así que `seasonBaseRoute => $routeName` cubre las 16 de una sola vez con este único archivo. **Antes de escribir el include, `grep -n "routeName" resources/views/specialties/ranking.blade.php` para confirmar el nombre exacto de la variable y su uso actual.**

`specialties/countries.blade.php` no tiene selector de server (Task 8) — `seasonBaseParams` va vacío `[]` (sin `server`).

Para el resto (`weapons`, `rivalries`, `map-kings`, `streaks`, `peak-times`, `timeouts`, `win-rate`, `rango`), `seasonBaseRoute` es el nombre de ruta fijo de cada una (`specialties.weapons`, `specialties.rivalries`, etc. — confirmar contra `routes/web.php`).

- [ ] **Step 1: Agregar el include a las 10 vistas**

- [ ] **Step 2: Correr toda la suite**

Expected: sin regresiones nuevas más allá de las ya esperadas por tasks anteriores todavía no cerradas (si se corre este Task antes de que Tasks 2-8 estén todas completas, cualquier vista sin `$seasonId` en su controller todavía va a fallar al renderizar — mismo patrón de "romper temporalmente hasta que las piezas encajen" que ya se usó en el sub-proyecto 2 entre sus Tasks 2 y 3). Si se ejecuta este plan en orden (Task 9 después de 2-8), no debería pasar nada de esto.

- [ ] **Step 3: Commit**

```bash
git add resources/views/specialties/ranking.blade.php resources/views/specialties/weapons.blade.php resources/views/specialties/rivalries.blade.php resources/views/specialties/map-kings.blade.php resources/views/specialties/streaks.blade.php resources/views/specialties/countries.blade.php resources/views/specialties/peak-times.blade.php resources/views/specialties/timeouts.blade.php resources/views/specialties/win-rate.blade.php resources/views/specialties/rango.blade.php
git commit -m "Selector de temporada en las 10 plantillas de /especialidades"
```

---

### Task 10: Equipos + `PlayerRankCalculator` por temporada

**Files:**
- Modify: `app/Support/PlayerRankCalculator.php`
- Modify: `app/Http/Controllers/SpecialtyController.php` (`rango()`, para que reuse el calculator scopeado en vez de la lógica del Task 5 directo, si no quedó ya así)
- Modify: `app/Http/Controllers/TeamBalanceController.php`
- Modify: `app/Http/Controllers/Admin/ConsoleController.php`
- Test: `tests/Feature/PlayerRankCalculatorSeasonTest.php`

**Interfaces:**
- Produces: `PlayerRankCalculator::calculateForServer(Server $server, int|string $seasonId = null): Collection` — `$seasonId` default `null` resuelve internamente a `Season::current()->id` (Equipos no tiene selector propio, siempre pide la activa explícita o implícita).

`PlayerRankCalculator::calculateForServer()` ya tiene la lógica de percentiles/rangos A-E extraída de `rango()` (ver CLAUDE.md, sección "Balanceador de equipos por rango") — confirmar su implementación actual leyendo el archivo antes de tocarlo, dado que el Task 5 de este plan ya reescribió `rango()` para leer de `KillAggregator::aggregate()` en vez de `PlayerServerStat`; **`PlayerRankCalculator` probablemente todavía lee `PlayerServerStat` directo** (no se tocó en el Task 5, que solo tocó el método del controller) — este Task es donde se corrige eso de raíz.

- [ ] **Step 1: Leer `PlayerRankCalculator::calculateForServer()` tal cual está hoy**

Confirmar cómo arma `$stats`/`$matches`/`$played`/`$won` — probablemente casi idéntico a lo que tenía `rango()` antes del Task 5 (mismo autor, misma extracción). Si el Task 5 ya dejó `rango()` sin duplicar esta lógica (llamando directo a `PlayerRankCalculator::calculateForServer()` en vez de tener su propia copia), este Task es más simple: solo agregarle `$seasonId` al calculator. Si `rango()` todavía tiene lógica propia sin pasar por el calculator, **unificarlos acá** (que `rango()` llame al calculator scopeado, sin duplicar el cálculo de percentiles dos veces en el código).

- [ ] **Step 2: Escribir el test que falla**

Fixture de 2 temporadas con partidas completas y kills reales — verificar que `PlayerRankCalculator::calculateForServer($server)` (sin segundo argumento) da los rangos de la temporada activa solamente, y que pasando el id de la temporada vieja explícito da los de esa.

- [ ] **Step 3: Correr el test y confirmar que falla**

- [ ] **Step 4: Agregar `$seasonId` al calculator**

Mismo cambio de fondo que Task 5 hizo en `rango()`: reemplazar la lectura de `PlayerServerStat` por `KillAggregator::aggregate()` scopeado a `GameMatch::forSeason($seasonId ?? Season::current()->id)->pluck('id')`, y el `$matches`/`GameMatch::where(...)` con el mismo `whereIn('id', $matchIds)`.

- [ ] **Step 5: Actualizar los 2 call-sites**

`TeamBalanceController` y `Admin\ConsoleController` llaman a `PlayerRankCalculator::calculateForServer($server)` — sin cambios necesarios en la llamada en sí (el default ya resuelve a la temporada activa), solo confirmar que siguen compilando/pasando sus propios tests existentes (`TeamBalancerTest.php`, si sigue existiendo — Equipos no está en git todavía, ver CLAUDE.md, así que puede que estos 2 archivos ni existan en este árbol; si no existen, saltar este Step y anotarlo en el reporte).

- [ ] **Step 6: Correr el test y confirmar que pasa, correr toda la suite**

- [ ] **Step 7: Commit**

```bash
git add app/Support/PlayerRankCalculator.php app/Http/Controllers/SpecialtyController.php tests/Feature/PlayerRankCalculatorSeasonTest.php
git commit -m "PlayerRankCalculator (rangos + Equipos) scopeado a la temporada activa"
```

---

### Task 11: Documentar, backup, y desplegar

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Agregar una entrada en `CLAUDE.md`**

Mismo estilo que la entrada "Ranking por temporada" ya existente: qué cambió (22 de 25 páginas de `/especialidades` ahora respetan temporada, default activa), qué quedó fuera y por qué (bombas/daño/desconexiones, sin datos para reconstruir retroactivamente), la reescritura de `mapKings`/`rango`/Equipos, y el link al spec.

- [ ] **Step 2: Commit**

- [ ] **Step 3: Backup de producción ANTES de desplegar**

Mismo procedimiento que el sub-proyecto 2 (Task 6): `php artisan backup:run` + tarball completo de `/var/www/cod2.4livepro.com`.

- [ ] **Step 4: Desplegar selectivo (NO `git archive` completo)**

**Importante, aprendido en el sub-proyecto 2:** este VPS tiene el módulo "Equipos" desplegado directo (no está en git) — `routes/web.php` en producción ya tiene la ruta `/equipos` agregada a mano. Un `git archive HEAD | tar -x` completo pisaría esa ruta y `leaderboard.blade.php`/`app.blade.php` (que también tienen ediciones de Equipos no versionadas). Desplegar archivo por archivo vía `scp`, igual que el sub-proyecto 2: todos los `.php`/`.blade.php` que este plan tocó (Tasks 1-10), la migración si la hay (este plan no agrega ninguna), NUNCA `routes/web.php` (nadie de este plan lo toca, así que no hace falta ni considerarlo).

- [ ] **Step 5: Verificar en producción**

`curl` a cada una de las 22 rutas en alcance (con y sin `?season=all`) — todas deben dar `200`. Confirmar visualmente (o con `grep` del HTML) que el selector de temporada aparece en las 10 vistas.

- [ ] **Step 6: Rollback, si algo falla**

Re-desplegar (vía `scp`, no `git archive`) los mismos archivos en su versión anterior al merge de este sub-proyecto.
