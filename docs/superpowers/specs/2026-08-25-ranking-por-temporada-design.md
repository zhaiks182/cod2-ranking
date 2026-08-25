# Ranking por temporada (Sub-proyecto 2 de 3)

## Contexto y objetivo

Sub-proyecto 1 (infraestructura base de temporadas, ya en producción) dejó `Season`
(`Season::current()`, tabla `seasons`) y `matches.season_id` (asignado una sola vez al
crear cada partida, nunca reasignado) — pero `/ranking` y `/jugador/{id}` siguen
mostrando el acumulado histórico completo, sin ningún corte por temporada.

Este sub-proyecto hace que `/ranking` y `/jugador/{id}` respeten la temporada — con
la temporada **activa** como default en todo el sitio (no el histórico), y la
posibilidad de elegir cualquier temporada cerrada o "Todo el historial".

Spec del sub-proyecto 1 (contexto del modelo `Season`):
`docs/superpowers/specs/2026-08-25-temporadas-infraestructura-base-design.md`.

## Decisiones ya tomadas (con el dueño)

- El selector de temporada **reemplaza** al filtro de fecha manual que ya existe en
  `/ranking` (`from`/`to`) — se saca de la UI, ya no hace falta.
- **Default en todo el sitio (no solo `/ranking`): la temporada activa**, no el
  histórico completo. Cualquier link a un perfil de jugador desde cualquier parte del
  sitio (dashboard, especialidades, popovers) va a mostrar temporada activa por
  defecto salvo que la URL traiga `?season=` explícito.
- Se agrega una opción **"Todo el historial"** al selector (`?season=all`) — no se
  pierde la vista que ya existía, solo deja de ser la default.
- **Bug heredado a corregir en este mismo sub-proyecto:** el camino de cálculo al
  vuelo que ya existe (`LeaderboardController::aggregateFromKills()`, hoy usado solo
  con el filtro de fecha manual) no excluye partidas abandonadas sin resultado real
  — a diferencia de las tablas pre-calculadas, que sí lo hacen desde un fix de esta
  misma sesión (`GameMatch::scopeAbandonedWithoutConclusion()`). Se corrige de una,
  ya que la vista por temporada se construye sobre ese mismo mecanismo.
- **Alcance del perfil de jugador: TODAS las secciones respetan la temporada**
  (números principales, mejores mapas, arma favorita, arma más equipada, team-kills,
  últimas bajas/muertes) — no solo un subconjunto.
- **"Arma más equipada" necesita una migración nueva** (`player_weapon_picks.season_id`,
  mismo patrón que `matches.season_id`) porque hoy es un acumulador puro sin ninguna
  referencia temporal — confirmado que vale la pena el trabajo extra en vez de
  dejarla como excepción.
- **Backup + rollback son parte explícita del plan** (ver "Rollback" más abajo) — no
  hay ningún `DELETE`/`DROP` en este sub-proyecto, todo es aditivo.

## Arquitectura: cálculo al vuelo, no una tabla de acumuladores nueva

`LeaderboardController` ya tiene el patrón exacto que hace falta:
`aggregateFromKills()` arma un ranking con un join `kills → rounds → matches`, hoy
filtrando por rango de fechas manual. Se extiende para filtrar por
`matches.season_id` en vez de (o adicionalmente a) fecha, y **pasa a ser el único
camino** — las tablas pre-calculadas `PlayerServerStat`/`PlayerMapStat` dejan de
leerse para el ranking principal (siguen existiendo, sin tocar, por si algo más las
usa — ver "Fuera de alcance").

**Por qué no una tabla `player_season_stats` pre-calculada:** ese patrón
("acumulador + corrección retroactiva") ya causó varios bugs reales documentados en
`CLAUDE.md` (kills de partida en curso borrados en vivo por
`cod2:recalculate-stats`, condición de carrera en `cod2:parse-log`, etc.). Con ~49
partidas / ~12k kills reales hoy, calcular al vuelo con un join es rápido y no
necesita ningún mecanismo de sincronización aparte — se puede reconsiderar con cache
o una tabla derivada si el volumen crece mucho y esto se vuelve lento, pero no antes
de que haga falta (YAGNI).

## Modelo de datos

Un solo cambio: **`player_weapon_picks.season_id`** (columna nueva, nullable a nivel
de esquema — mismo motivo que `matches.season_id`: `doctrine/dbal` no instalado,
SQLite no soporta bien `Blueprint::change()` sin reconstruir la tabla). Asignada una
sola vez, en `ParseCod2Log` donde hoy se hace
`PlayerWeaponPick::firstOrCreate(['player_id' => ..., 'weapon' => ...])`
(`app/Console/Commands/ParseCod2Log.php:526`) — pasa a incluir
`'season_id' => Season::current()->id` en las condiciones de búsqueda/creación. El
unique constraint pasa de `[player_id, weapon]` a `[player_id, weapon, season_id]`
(un jugador puede tener un arma favorita distinta por temporada). Backfill de las
filas existentes a "Temporada 1", mismo patrón que la migración del sub-proyecto 1.

`kills`, `rounds`, `matches` no necesitan más columnas — todo lo demás se deriva vía
join a `matches.season_id` en el momento de la query.

## `/ranking` — selector de temporada

**URL:** `?season={id}` (temporada específica) o `?season=all` ("Todo el
historial"). Sin el parámetro → temporada activa. Reemplaza a `from`/`to`, que se
sacan de la UI (el controller puede seguir aceptándolos internamente sin exponerlos,
si simplifica la migración del código, pero no hace falta — se eliminan).

**Selector:** dropdown con la temporada activa primero, después las cerradas (más
reciente primero), "Todo el historial" al final.

**`aggregateFromKills()`** se extiende: WHERE `matches.season_id = ?` (salvo
`season=all`, que no filtra) + WHERE NOT `matches.id` en el subquery de
`GameMatch::abandonedWithoutConclusion()`.

**`buildMapGroups()`** también se scopea por temporada cuando hay una seleccionada
(no en `season=all`) — agregar `->whereHas('matches', fn ($q) => $q->where('season_id', $seasonId))`
o el join equivalente, para que la lista de sesiones/fechas de un mapa dentro de una
temporada no incluya sesiones de otras temporadas. Consistente con "el selector de
temporada reemplaza a la navegación por fecha": las date-pills de un mapa deben
quedar acotadas a la temporada activa igual que el resto de la vista.

**Axis/Allies panel** (`$rounds`, `$axisRows`/`$alliesRows`/`$sideScores`): ya está
scopeado a un mapa+sesión específica (vía `$mapCodes` + `from`/`to` de esa sesión
puntual), no al selector de temporada en sí — no necesita cambios más allá de que
`$from`/`$to` sigan derivándose igual que hoy internamente para identificar la
sesión, aunque ya no vengan de la URL.

## `/jugador/{id}` — perfil scopeado por temporada

Mismo parámetro `?season={id}`/`?season=all`, mismo default (temporada activa) en
**todo el sitio** — ningún otro link al perfil necesita cambiar (siguen sin `?season=`,
heredan el default); solo `/ranking` arma el link con `?season=` explícito para que
el perfil "herede" la temporada que se estaba mirando.

Cambios en `PlayerController::show()`:

- **Números principales** (kills/deaths/headshots/K-D): hoy `$player->kills_total`
  etc (columnas de `players`, de por vida). Pasan a calcularse al vuelo con el mismo
  patrón que `KillAggregator` pero para un solo jugador, scopeado a
  `matches.season_id` (o sin filtro en `season=all`) y excluyendo partidas
  abandonadas, igual que el ranking.
- **Mejores mapas** (`mapStats`): hoy `player_map_stats` (de por vida). Pasa a un
  join `kills→rounds→matches` agrupado por mapa normalizado, mismo scope.
- **Arma favorita** (`$favoriteWeapon`) y **team-kills** (`$teamkillCount`): ya son
  queries en vivo contra `kills`/`rounds` (no leen tablas pre-calculadas) — se les
  agrega el `where('matches.season_id', ...)` vía el join a `matches` que ya hace
  falta agregar a esas queries (hoy solo joinean `rounds`, no `matches`).
- **Últimas bajas/muertes** (`$recentKills`/`$recentDeaths`, 15 más recientes): se
  les agrega el mismo filtro — "recientes dentro de la temporada elegida", vía el
  mismo join.
- **Arma más equipada** (`$mostEquippedWeapon`): usa la columna nueva
  `player_weapon_picks.season_id`.

`players.kills_total` y las demás columnas de acumulado de por vida en `players`
**quedan intactas, sin tocar** — dejan de leerse desde `/ranking` y el perfil, pero
no se borran (quedan como posible respaldo/optimización futura).

## Rollback

Nada en este sub-proyecto borra o modifica datos existentes — todo es aditivo
(una columna nueva, backfilleada) o un cambio de qué se lee (de tablas
pre-calculadas a cálculo al vuelo), no de qué se escribe. `player_server_stats`,
`player_map_stats` y `players.kills_total` siguen actualizándose exactamente igual
que hoy (nadie toca `ParseCod2Log::recordKill()` en lo que ya escribe ahí), solo
dejan de leerse desde estas dos vistas.

- **Backup antes de desplegar:** dump de la base de datos real de producción +
  tarball del código desplegado (mismo procedimiento que sub-proyecto 1).
- **Rollback de código:** re-desplegar el commit anterior al merge de este
  sub-proyecto — instantáneo, sin pérdida de datos.
- **Rollback de esquema:** la migración nueva (`player_weapon_picks.season_id`) es
  aditiva; su `down()` la revierte limpio, aunque en la práctica no hace falta
  correrlo — con re-desplegar el código viejo alcanza, la columna de más no rompe
  nada mientras nadie la lea.

## Testing (TDD)

Mismo patrón que sub-proyecto 1: tests reales contra SQLite en un entorno
descartable en el VPS (sin PHP local en esta máquina). Casos clave:

- `/ranking` sin parámetro muestra solo kills/deaths de la temporada activa.
- `/ranking?season=all` muestra el acumulado de todas las temporadas.
- `/ranking?season={id}` (una temporada cerrada) muestra solo esa.
- Una partida abandonada (sin resultado real) no aporta kills a ninguna vista de
  `/ranking`, sin importar la temporada elegida.
- `buildMapGroups()` scopeado por temporada no mezcla sesiones de otra temporada en
  las date-pills de un mapa.
- `/jugador/{id}` sin parámetro muestra los números de la temporada activa
  (comparados contra sumar a mano los kills/muertes reales de esa temporada en los
  fixtures del test).
- `/jugador/{id}?season=all` muestra el acumulado histórico completo.
- La migración de `player_weapon_picks.season_id`: backfillea las filas existentes a
  "Temporada 1"; un pick nuevo después de cerrar una temporada se asigna a la nueva.

## Fuera de alcance (a propósito)

- Las ~20 páginas de `/especialidades` siguen sin temporada — sub-proyecto 3.
- `player_server_stats`/`player_map_stats`/`players.kills_total` no se recalculan ni
  se eliminan — quedan intactas, simplemente dejan de ser la fuente de
  `/ranking`/`/jugador/{id}`.
- Sin cache ni tabla derivada para las queries al vuelo — se evalúa solo si el
  volumen real lo justifica más adelante.
