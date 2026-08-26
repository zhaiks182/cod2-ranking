# Especialidades por temporada (Sub-proyecto 3 de 3)

## Contexto y objetivo

Sub-proyecto 1 (infraestructura base) y sub-proyecto 2 (`/ranking` y `/jugadores/{guid}`)
ya están en producción. `/especialidades` (25 páginas: granadas, headshots, fuego amigo,
suicidios, eficiencia, mapas ganados, ranking por arma, rivalidades, reyes de mapa, horas
jugadas, rachas de mapas, actividad reciente, países, clutches, rachas de bajas, más
hablador, hora pico, timeouts, bash, win-rate, rangos, bombas, daño, desconexiones) sigue
mostrando el histórico completo del sitio, sin ningún corte por temporada.

Este sub-proyecto extiende el mismo mecanismo (`GameMatch::scopeForSeason($seasonId)`,
selector de temporada reusable `partials.season-selector`) a **22 de esas 25 páginas**.

## Decisiones tomadas con el dueño (2026-08-26)

- **Alcance: todas las páginas posibles.** Confirmado explícitamente que se quiere el
  selector en todas, no solo las principales.
- **Bombas, Daño, y Desconexiones quedan FUERA de alcance de este sub-proyecto.**
  Leen `player_server_stats.damage_dealt`/`bomb_plants`/`bomb_defuses`/
  `mid_round_disconnects` — acumuladores puros sin ninguna tabla de detalle por evento
  (`Damage;`/`Bomb;`/`Disconnected;` del log nunca se guardan línea por línea, ver
  CLAUDE.md sección "Chat y eventos de partida"). Sin ese detalle no hay forma de saber
  retroactivamente cuánto de lo ya acumulado pasó en cada temporada vieja — mismo tipo de
  limitación que ya se resolvió para `matches.season_id` con un backfill, pero ahí sí
  había datos suficientes (la propia fila de `matches`) para reconstruirlo; acá no los
  hay. El dueño eligió explícitamente sacarlas del alcance por ahora en vez de forzar un
  backfill a "Temporada 1" o dejarlas sin selector mientras el resto sí lo tiene.
- **`/especialidades/rangos` sigue siendo la base del balanceador de Equipos**
  (`PlayerRankCalculator::calculateForServer()`, extraído de `rango()` — ver CLAUDE.md
  sección "Balanceador de equipos por rango"). El dueño confirmó que Equipos también debe
  calcular el rango de cada jugador solo con la temporada activa (no toda su carrera) —
  consistente con el resto del sitio.

## Arquitectura: mismo patrón que sub-proyecto 2, sin tabla nueva

Todo se sigue calculando al vuelo. `SpecialtyController` gana un helper privado nuevo,
`resolveSeason(Request $request): array` (mismo patrón exacto que ya está duplicado
inline en `LeaderboardController`/`PlayerController` — justifica extraerlo acá porque
son 22 sitios de uso, no 2):

```php
private function resolveSeason(Request $request): array
{
    $seasons = Season::orderByDesc('started_at')->get();
    $seasonParam = $request->query('season');
    $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
    $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

    return [$seasons, $seasonId, $matchIds];
}
```

`sdKills()` (el helper compartido ya existente) gana un parámetro `$matchIds` y aplica
`whereIn('kills.match_id', $matchIds)`.

## Categorización de los 22 métodos en alcance

**Grupo A — reemplazar `PlayerServerStat` por `KillAggregator::aggregate()` scopeado
(reescritura real, mismo patrón que Task 2/4 del sub-proyecto 2):**
`grenades`, `headshots`, `friendlyFire`, `efficiency`, `bashCalls` — las 5 leen una
columna de `PlayerServerStat` (de por vida) que `KillAggregator::aggregate()` ya calcula
al vuelo (`grenade_kills`, `headshots`, `teamkills`, kills/deaths para K-D, `bash`).

**Grupo B — reescribir como query en vivo (no hay acumulador previo, es un caso especial):**
`suicides` — hoy lee `PlayerServerStat.suicides`; se reescribe como
`Kill::where('is_suicide', true)->whereIn('match_id', $matchIds)` agrupado por
`attacker_player_id` (join a `rounds` solo para el filtro de server, `kills.match_id` ya
identifica la partida directo).

**Grupo C — ya son queries en vivo vía `sdKills()`, solo agregar `$matchIds`:**
`grenadeDeaths`, `weapons`, `rivalries`, `recentActivity`, `peakTimes` — el cambio es
pasar `$matchIds` a `sdKills()`. `grenadeDeaths`/`bashCalls` además pisan una columna
`kills` "de referencia" leída de `PlayerServerStat` — se reemplaza por el conteo ya
disponible en el resultado de `KillAggregator::aggregate()`/la propia query en vivo.

**Grupo D — ya son queries sobre `GameMatch`/`Round`, solo agregar
`whereIn('id'|'match_id', $matchIds)`:**
`mapsWon`, `streaks`, `winRate`, `rango`, `playtime`, `clutches` — sin cambio de forma,
un `whereIn` más en la query que ya arma la lista de partidas/rondas candidatas.

**Grupo E — reescribir desde `PlayerMapStat` a agregación en vivo por mapa:**
`mapKings` — mismo espíritu que `KillAggregator::aggregateByMap()` (ya existe, usado por
el perfil de jugador) pero agrupado por TODOS los jugadores de cada mapa, no uno solo —
nuevo método `KillAggregator::topByMap(Closure $baseQuery): Collection` (kills totales
por mapa + el jugador con más kills en ese mapa), reusado acá.

**Grupo F — ya tienen `match_id` directo en su tabla, solo agregar `whereIn`:**
`chattiest` (`ChatMessage.match_id`), `timeouts` (`MatchEvent.match_id`) — ninguna de las
dos tablas tiene `season_id` propio (a propósito, mismo criterio que `rounds`/`kills`:
se deriva vía `match_id` → `matches.season_id`, sin duplicar la columna).

**Grupo G — caso especial, sin filtro de servidor (ya así antes de este cambio):**
`countries` — hoy es una foto global de TODOS los jugadores con IP conocida, sin
noción de actividad reciente. Pasa a mostrar solo jugadores con al menos un kill o
muerte dentro de `$matchIds` de la temporada elegida (multi-servidor: la unión de todos
los servers activos, ya que la página nunca tuvo selector de server) — "quién jugó esta
temporada, por país", consistente con el resto de la sección. `season=all` recupera
exactamente el comportamiento actual (todos los jugadores con IP conocida, alguna vez).

## Vistas

Gracias a que 16 de los 22 métodos en alcance comparten una sola plantilla
(`specialties.ranking`), el trabajo de vista es chico: agregar
`@include('partials.season-selector', [...])` (ya reusable, del sub-proyecto 2) al
header de `specialties.ranking` (cubre 16 páginas de una) y, por separado, a
`specialties.weapons`, `specialties.rivalries`, `specialties.map-kings`,
`specialties.streaks`, `specialties.countries`, `specialties.peak-times`,
`specialties.timeouts`, `specialties.win-rate`, `specialties.rango` (9 vistas más,
1 include cada una). Total: 10 archivos de vista tocados, no 22.

## Equipos + `/especialidades/rangos`

`app/Http/Controllers/Admin/ConsoleController.php` y
`app/Http/Controllers/TeamBalanceController.php` llaman a
`PlayerRankCalculator::calculateForServer($server)` sin noción de temporada.
`PlayerRankCalculator` se extiende con un parámetro `$seasonId` (default: temporada
activa, mismo criterio del resto del sitio — Equipos no tiene selector propio de
temporada, siempre usa la activa) y aplica el mismo `whereIn` que `rango()`.

## Fuera de alcance (explícito)

- `bombs`, `damage`, `disconnects` — ver "Decisiones tomadas" arriba.
- `player_server_stats`/`player_map_stats`/`players.*_total` no se tocan ni se borran —
  mismo criterio que sub-proyecto 2.
- Ninguna tabla de acumulados nueva por temporada.

## Testing (TDD)

Mismo patrón que sub-proyecto 2: tests reales contra SQLite en un entorno descartable
en el VPS (sin PHP local en esta máquina). Un caso mínimo por método en alcance
(partida en temporada vieja no debe aportar al total mostrado sin filtro explícito;
`season=all` sí la incluye) — no hace falta un test por cada columna de cada método,
alcanza con probar que el filtro de match_id se aplicó de verdad en la query.

## Rollback

Igual que sub-proyecto 2: todo aditivo (sin tabla nueva, sin migración de datos),
`down()` no aplica. Revertir es re-desplegar el código anterior.
