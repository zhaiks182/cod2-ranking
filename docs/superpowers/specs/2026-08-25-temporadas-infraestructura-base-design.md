# Temporadas — Infraestructura base (Sub-proyecto 1 de 3)

## Contexto y objetivo

Hoy todas las estadísticas del sitio (`players.kills_total`, `player_map_stats`,
`player_server_stats`, y todas las páginas de `/ranking` y `/especialidades`) son
"planas" — un acumulado de por vida sin ningún corte temporal. El dueño quiere poder
arrancar **temporadas**: el admin cierra la temporada activa e inicia una nueva desde
el panel, el ranking/especialidades quedan en cero para la temporada nueva (nadie
compite contra meses de ventaja acumulada), pero la temporada anterior sigue
consultable con toda su data intacta.

Es un cambio grande — toca casi todo el pipeline de stats. Se decidió dividirlo en 3
sub-proyectos, cada uno con su propia spec → plan → implementación:

1. **Infraestructura base** (esta spec) — modelo `Season`, control de admin para
   cerrar/abrir temporadas, `season_id` en `matches`, backfill de todo el historial
   existente como "Temporada 1". **Sin ningún cambio visible en el sitio público.**
2. **Ranking principal por temporada** — `/ranking` con selector de temporada y stats
   agregadas por temporada (fuera de alcance de esta spec).
3. **Especialidades por temporada** — las ~20 páginas de `/especialidades` (fuera de
   alcance de esta spec).

Esta spec cubre **solo el sub-proyecto 1**. Las decisiones de diseño de 2 y 3 se
tomarán en sus propias sesiones de brainstorming, una vez que esta base esté en
producción y probada.

## Decisiones ya tomadas (con el dueño)

- **Objetivo:** competencia periódica — el ranking activo debe reflejar solo la
  temporada actual, no un acumulado histórico.
- **Disparador:** 100% manual, sin cron ni fecha de corte automática. Solo el admin
  decide cuándo cerrar una temporada.
- **Alcance:** una sola temporada activa **global** para todo el sitio (no por
  servidor) — hoy solo hay un servidor real (Pug Latam), y una temporada por servidor
  agregaría complejidad sin un caso de uso real todavía.
- **Nombres:** el admin escribe el nombre de cada temporada nueva (no auto-generado
  sin posibilidad de editar).
- **Partida en curso al cerrar:** sin lógica especial — la partida que estaba en curso
  cuando se cierra la temporada queda completa en la temporada vieja (ver "Modelo de
  datos" para por qué esto no requiere ningún manejo extra). No hace falta advertencia
  en el panel.

## Modelo de datos

### Tabla `seasons`

```
id              bigint, PK
name            string, requerido
started_at      timestamp
ended_at        timestamp, nullable -- NULL = es la temporada activa
timestamps
```

`Season::current()`: la fila con `ended_at IS NULL`. Debe existir exactamente una en
todo momento (garantizado por el flujo de cierre/apertura, ver más abajo — nunca hay
una ventana donde no exista ninguna activa).

### `matches.season_id`

Columna nueva, FK a `seasons.id`, **NOT NULL** (después del backfill). Se asigna **una
sola vez, en el momento de creación de la partida** — `ParseCod2Log::openRound()`
(`app/Console/Commands/ParseCod2Log.php:266`, donde hoy se hace `GameMatch::create([...])`)
agrega `'season_id' => Season::current()->id` al array de creación.

`rounds` y `kills` **no** necesitan su propia columna `season_id` — ya cuelgan de
`match_id`, así que cualquier consulta futura por temporada (sub-proyectos 2/3) hace
join contra `matches.season_id`. Se evaluó denormalizar `season_id` directo en `kills`
para evitar ese join más adelante, pero es optimización prematura sobre ~12k filas
totales hoy — se puede agregar después, sin cambiar este modelo, si algún día hiciera
falta por performance.

**Por qué un FK explícito y no un rango de fechas:** `matches.is_backfilled` ya
documenta el problema — las partidas del backfill inicial (`--from-start`) quedaron
con `started_at`/`ended_at` pegados al momento del backfill, no a cuándo se jugaron
realmente (ver `CLAUDE.md`, bitácora de bugs #6). Un corte de temporada por rango de
fechas clasificaría mal esas partidas. Asignar el FK explícitamente al crear cada
partida es robusto a eso — no depende de que `started_at` sea confiable.

**Por qué no hace falta manejo especial para una partida en curso al cerrar:** como el
`season_id` se asigna una única vez, en el momento de creación, una partida ya en
curso simplemente conserva el `season_id` de la temporada que estaba activa cuando
arrancó. La siguiente partida que se cree (después del corte) ya recibe el nuevo
`season_id`. No hay ventana donde una partida quede "a mitad" entre dos temporadas.

## Backfill: "Temporada 1"

Una sola migración (`create_seasons_table_and_backfill_matches`):

1. Crea `seasons`.
2. Agrega `matches.season_id`, nullable por ahora.
3. Inserta la fila "Temporada 1": `started_at` = `MIN(matches.started_at)` si existe
   alguna partida, si no `now()`; `ended_at = null`.
4. `UPDATE matches SET season_id = <id de Temporada 1>` para **todas** las filas
   existentes.
5. Altera `matches.season_id` a `NOT NULL`.

Mismo patrón de "agregar → backfillear → endurecer la constraint en la misma
migración" que ya se usó en `2026_08_25_161907_replace_hosted_servers_max_concurrent_with_ports_list.php`
esta misma sesión — cero downtime, cero partidas huérfanas, el deploy no cambia nada
visible hasta que el admin cierre la primera temporada a mano.

## `Season` (modelo)

`app/Models/Season.php`:

- `current(): self` — estático, `static::whereNull('ended_at')->firstOrFail()`.
- `matches(): HasMany`
- Sin lógica de cierre/apertura en el modelo — vive en el controller (es una acción de
  negocio con efectos secundarios claros — auditoría —, no una operación CRUD simple).

## Panel de admin

### Rutas

```
GET  /adm_cod2/temporadas            admin.seasons.index
POST /adm_cod2/temporadas            admin.seasons.store
```

(Mismo estilo que `admin.backups.index`/`admin.backups.store` — URL en español,
nombre de ruta en inglés, ver `routes/web.php`.)

### `Admin\SeasonController`

- `index()`: lista todas las temporadas (`Season::orderByDesc('started_at')->get()`),
  cada una con conteo de partidas (`withCount('matches')`) y si es la activa
  (`ended_at === null`). Pasa también un formulario para cerrar/abrir.
- `store(Request $request)`:
  - Valida `name` (`required|string|max:100`).
  - Dentro de una transacción: `Season::current()->update(['ended_at' => now()])`,
    luego `Season::create(['name' => ..., 'started_at' => now(), 'ended_at' => null])`.
  - `AdminAction::record('seasons.close', "Cerró \"{temporada vieja}\" e inició \"{temporada nueva}\"")`
    — mismo patrón que el resto de acciones auditadas (`Admin\MatchController@destroy`,
    `Admin\PlayerController@clearIp`, etc., ver `CLAUDE.md` "Log de auditoría").
  - Redirige con `status` de éxito.

### Vista `admin/seasons/index.blade.php`

Tabla simple: Nombre | Desde | Hasta ("— activa —" si `ended_at` es null) | Partidas.
Arriba, un form de una sola línea: input de texto (nombre de la temporada nueva) +
botón "Cerrar temporada actual e iniciar esta". Confirmación con
`onsubmit="return confirm(...)"` (mismo patrón que "Eliminar" en
`admin/servers/index.blade.php`) — es una acción con efecto real, sin vuelta atrás
fácil (aunque no destruye datos, cambia qué partidas nuevas se contabilizan dónde).

### Nav

Link "Temporadas" en el dropdown "Sistema" de `layouts/admin.blade.php` (junto a
Respaldos, Discord, Contraseña).

## Testing (TDD)

- `Season::current()` devuelve la temporada con `ended_at` null.
- Migración: backfillea todas las partidas existentes a "Temporada 1"; `season_id`
  queda `NOT NULL`.
- `ParseCod2Log::openRound()`: una partida nueva parseada recibe el `season_id` de
  `Season::current()` en el momento de creación.
- `Admin\SeasonController@store`:
  - Cierra la temporada activa (`ended_at` puesto) y crea la nueva activa con el
    nombre dado.
  - Validación: `name` requerido — rechaza vacío.
  - Se crea un `AdminAction` al cerrar.
  - **Test de punta a punta:** cerrar temporada, correr `cod2:parse-log` sobre una
    partida nueva (o invocar `openRound()` directo), confirmar que esa partida cae en
    la temporada NUEVA, no en la vieja — es el test que prueba que el corte realmente
    separa lo de antes de lo de después.
- Guard: `Season::current()` nunca debe fallar por "no hay ninguna activa" en
  condiciones normales — cubierto porque `store()` siempre crea la nueva dentro de la
  misma transacción que cierra la vieja.

## Fuera de alcance (a propósito)

- `/ranking` y las ~20 páginas de `/especialidades` no cambian nada — siguen
  mostrando el acumulado de siempre. Eso es el sub-proyecto 2 y 3.
- Sin fecha de corte automática/programada.
- Sin temporadas por servidor (solo global).
- **Vista pública de temporadas cerradas** (requisito confirmado por el dueño durante
  esta sesión, 2026-08-25: "el usuario podrá buscar la temporada que está cerrada y
  ver todos los datos estadísticos, independiente de las nuevas temporadas que se
  vayan a crear") — es sub-proyecto 2: un selector de temporada en `/ranking` que deje
  elegir cualquier temporada (la activa o cualquiera cerrada) y muestre sus stats
  completas, sin que abrir temporadas nuevas afecte ni oculte los datos de las
  anteriores. Esta spec ya deja la base que lo hace posible — `matches.season_id` es
  permanente por partida, así que la data de una temporada cerrada queda intacta y
  consultable para siempre, sin importar cuántas temporadas se abran después.
