# Bombas, daño y desconexiones por temporada

## Contexto y objetivo

El sub-proyecto "especialidades por temporada" (2026-08-26) dejó **3 de 25 páginas de
`/especialidades` explícitamente fuera de alcance**: `bombas` (`/bombas`), `daño`
(`/dano`), y "Se Fueron a Media Ronda" (`/desconexiones`, desconexiones a mitad de
ronda). Las tres leen columnas de `player_server_stats`
(`bomb_plants`/`bomb_defuses`/`damage_dealt`/`damage_taken`/`mid_round_disconnects`) —
acumuladores puros, sin ninguna tabla de detalle por evento, así que no había forma de
saber retroactivamente a qué temporada perteneció cada bomba/punto de daño/desconexión
ya contado.

El dueño probó el cierre de temporada en producción real (2026-08-27) y notó que estas
3 páginas seguían mostrando el histórico completo de la Temporada 1 incluso después de
crear una Temporada 2 — confirmado como comportamiento esperado (documentado desde el
sub-proyecto anterior), pero el dueño pidió resolverlo de fondo en vez de dejarlo así.

**Objetivo de este sub-proyecto:** que las 3 páginas respeten la temporada elegida
**a partir de ahora** (no retroactivamente — ver limitación más abajo), con el mismo
selector y contrato de URL que las otras 22 páginas de `/especialidades`.

## Decisión tomada con el dueño (2026-08-27)

- **Empezar a trackear `Bomb;`/`Damage;`/`Disconnected;` con vínculo a la partida,
  desde el momento del deploy en adelante.** Alternativa descartada: ocultar los
  valores por completo (más simple, pero pierde el dato del todo). Alternativa
  descartada en el pasado (documentada desde 2026-08-13): guardar cada línea cruda de
  `Damage;` igual que `kills` — `Damage;` tiene 10-20x más volumen que `Kill;`
  (dispara en cada impacto, no solo en cada baja), y ninguna página necesita ese nivel
  de detalle (nunca se pidió "lista de golpes", solo sumas/conteos). Este sub-proyecto
  agrega un acumulador **por partida** (no por golpe), que da exactamente la
  granularidad que hace falta para scopear por temporada sin el volumen de guardar
  cada hit.

## Limitación real, inevitable, no un defecto de este diseño

**Solo se puede atribuir a una temporada específica lo que se juegue después de este
deploy.** El log nunca guardó el detalle línea por línea con vínculo a partida para
estos 3 eventos — no hay ningún backfill posible, ni siquiera parcial (a diferencia de
`matches.season_id`, que sí se pudo backfillear porque la fila de `matches` en sí ya
tenía toda la información necesaria). Esto aplica también a la Temporada 1 actual, que
ya lleva partidas jugadas antes de este deploy: esas partidas van a seguir sin datos de
bombas/daño/desconexiones bajo `?season=1`, aunque sí sigan sumando al histórico
completo (`?season=all`). El dueño confirmó que esto es aceptable.

## Arquitectura

### Tabla nueva: `player_match_extras`

Una fila por (jugador, partida), igual de espíritu que `player_weapon_picks`
(acumulador incremental, pero keyed por `match_id` en vez de `season_id` — la
temporada se deriva por join a `matches.season_id`, igual que ya hacen
`kills`/`rounds`, sin duplicar la columna).

```php
Schema::create('player_match_extras', function (Blueprint $table) {
    $table->id();
    $table->foreignId('player_id')->constrained();
    $table->foreignId('match_id')->constrained();
    $table->unsignedInteger('bomb_plants')->default(0);
    $table->unsignedInteger('bomb_defuses')->default(0);
    $table->unsignedInteger('damage_dealt')->default(0);
    $table->unsignedInteger('damage_taken')->default(0);
    $table->unsignedInteger('mid_round_disconnects')->default(0);
    $table->timestamps();
    $table->unique(['player_id', 'match_id']);
});
```

Una sola tabla consolidada, no tres separadas — mismo criterio que
`bumpServerStatExtra()` ya usa hoy (un método, cinco contadores relacionados). Separar
en 3 tablas no aportaría nada real (mismo ciclo de vida, mismos call-sites) y sería más
migraciones/modelos que mantener sin beneficio.

### Parser: `ParseCod2Log::bumpServerStatExtra()` gana un vínculo a partida

`recordBomb()`, `recordDamage()`, y `recordDisconnect()` ya reciben `$currentRound`
(de donde sale `match_id`) — no hace falta tocar el parseo de las líneas en sí, solo
agregar el segundo destino de escritura. `bumpServerStatExtra()` gana un parámetro
`?int $matchId` (nullable a propósito: `recordDamage()`/`recordBomb()` ya validan
`$currentRound` antes de llamar, pero se mantiene defensivo):

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

`player_server_stats` **no cambia su comportamiento** — sigue siendo el total
histórico real de siempre, nunca se toca ni se recalcula. `player_match_extras` es
puramente aditivo (tabla nueva, nadie más la lee todavía).

Los 3 call-sites (`recordBomb`, `recordDamage`, `recordDisconnect`) pasan
`$currentRound->match_id` como el nuevo argumento.

### Controllers: `bombs()`, `damage()`, `disconnects()`

Mismo patrón que el resto de `/especialidades`: `[$seasons, $seasonId, $matchIds] =
$this->resolveSeason($request)`.

- **`?season=all`**: sigue leyendo `PlayerServerStat` exactamente como hoy (sin
  cambios en esa rama) — el histórico completo real, sin huecos.
- **Cualquier temporada específica (activa o cerrada)**: lee `PlayerMatchExtra`
  agregado por jugador, filtrado `whereIn('match_id', $matchIds)`. Una temporada sin
  ninguna partida posterior al deploy da una tabla vacía — honesto, no hay dato que
  mostrar, no se inventa nada.

Vistas: las 3 páginas ganan el mismo `@include('partials.season-selector', ...)` que
las otras 22 (mismo patrón, `seasonBaseRoute` fijo por página, `seasonBaseParams` con
`server`).

## Testing

- `ParseCod2LogExtrasSeasonTest.php`: verificar que un `Bomb;`/`Damage;`/
  `Disconnected;` real crea la fila correcta en `player_match_extras` con el
  `match_id` correcto, y que `player_server_stats` sigue incrementándose igual que
  antes (sin regresión).
- Tests de temporada por página (mismo patrón que `GroupASeasonTest`/etc. del
  sub-proyecto anterior): 2 temporadas, verificar que la vista sin `?season=` excluye
  la vieja, y `?season=all` sigue trayendo el total histórico completo (incluyendo
  cualquier dato pre-existente que solo viva en `player_server_stats`, no en
  `player_match_extras`).

## Deploy

Esto toca `ParseCod2Log`, que corre cada minuto contra el log real de producción —
mismo nivel de cuidado que cualquier cambio al parser (ver CLAUDE.md, bitácora de
bugs). Migración nueva (`player_match_extras`), sin tocar ninguna tabla existente.
Deploy selectivo de siempre (nunca `git archive` completo, nunca tocar `routes/web.php`
sin revisar — aunque este sub-proyecto no lo necesita tocar, ya tiene las rutas de
`bombas`/`dano`/`desconexiones` desde antes).
