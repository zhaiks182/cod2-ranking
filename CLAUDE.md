# CoD2 Stats — Pug Latam

Dashboard de estadísticas para uno o más servidores de Call of Duty 2 (mod CoD2x +
zPAM): rankings de jugadores, historial de partidas por mapa/fecha, estado del
servidor en vivo, y un panel de administración con consola RCON.

**VPS exclusivo desde el 2026-08-24** (`151.245.32.43`) — antes compartía VPS/LAMP
con otros proyectos de 4LivePro (`167.148.33.82`, alias SSH `iptvwatch`), ver sección
"Migración a VPS dedicado" más abajo para el detalle completo y por qué. El VPS viejo
sigue activo como respaldo, sin fecha de apagado decidida.

**Sitio público:** https://cod2.4livepro.com
**Panel admin:** https://cod2.4livepro.com/adm_cod2 (login por usuario, no email — no
enlazado desde el menú público a propósito)

## Stack

- Laravel 13 (PHP 8.3), Blade + Tailwind (CDN, sin build step — ver "Decisiones" más abajo)
- MySQL/MariaDB (`cod2_stats`)
- `geoip2/geoip2` para geolocalización de jugadores conectados (ver "Pendientes")

**Identidad visual (2026-08-17):** tema "gaming/esports" — fuentes de Google Fonts
Chakra Petch (texto general, `font-sans` en el config de Tailwind) y Russo One
(logo/display, `font-display`), colores custom `gsprimary`/`gsaccent` (azul/celeste)
agregados al `tailwind.config` inline en `layouts/app.blade.php`, esquinas más rectas
que antes (se sacó `rounded-lg` de varios componentes a propósito), y medallas de
emoji 🥇🥈🥉 (no SVG — un ícono de estrella coloreada se probó primero pero no se
leía como medalla) en el top 3 del dashboard.

## Cómo llegan los datos

El gameserver de CoD2 (`/home/gameserver/1.3/puG/main/games_mp.log`) escribe eventos
de juego en texto plano. `cod2:parse-log` (cron cada minuto, ver `routes/console.php`)
parsea el log completo y guarda kills/rondas/partidas/jugadores en la base de datos.
Guarda su posición de lectura (`log_parser_state.byte_offset`) para no releer todo
cada vez.

**El mensaje de bienvenida automático (`cod2:watch-connects` + servicio systemd
`cod2-watch-connects`) se eliminó** (2026-08-10, a pedido del dueño) — ya no existe
`WatchConnects.php`, ni la columna `servers.welcome_message`, ni el servicio en el
VPS. Si se reintroduce esta idea en el futuro, el código de referencia (regex de
`Connected;`, dedupe por guid, etc.) está en el historial de git antes de ese commit.

### Cuándo se crea una "partida" (`matches`)

Una partida se crea solo cuando llega `RoundStart;`, **no** en cada `InitGame:`. CoD2
manda un `InitGame:` cada vez que se recarga el lobby de ready-up de zPAM, así que
crear una partida ahí generaba registros vacíos por cada ciclo de espera. El mapa y
gametype de la próxima partida se leen del `InitGame:` más reciente pero se guardan en
`log_parser_state.pending_map/pending_gametype` — porque `RoundStart;` no trae esa
info, y el parser corre en procesos separados cada minuto, así que hay que recordarlo
entre corridas.

**Deathmatch no manda `RoundStart;` en absoluto** (no tiene rondas). Para ese caso,
`recordKill()` tiene un respaldo: si llega un `Kill;` sin ronda abierta, abre una
usando el mapa/gametype pendiente. Verificado en vivo con una partida real de
`wawa_3daim` (DM) — funcionó correctamente.

Un mismo `match` agrupa rondas consecutivas del mismo mapa+gametype. Cambia el mapa o
el gametype → partida nueva. El cambio de bando a medio tiempo (swap de equipos en SD)
**no** crea una partida nueva, porque solo se compara mapa+gametype, no equipos.

### Identidad del jugador (HWID, no nombre)

CoD2x reemplaza el GUID de PunkBuster por un HWID de 32 bits (hash de componentes de
hardware), y el servidor lo escribe como el campo `guid` en cada línea del log. Ese
valor es estable entre sesiones/reconexiones — por eso los jugadores se identifican
por `guid`, no por nombre (`players.guid`, único). El nombre visible se guarda aparte
en `player_aliases`, dedupeado por `name_plain` (sin códigos de color `^N`), porque un
mismo nombre puede llegar con distintos códigos de color según venga del log o de una
consulta RCON en vivo.

**Bots siempre tienen `guid=0`** — son indistinguibles entre sí, así que nunca se les
crea una fila en `players` (`upsertPlayer()` devuelve `null` para guid=0). Sus kills
igual se guardan en `kills` (con `attacker_guid=0`/`attacker_name` tal cual), pero con
`attacker_player_id` nulo, así que no aparecen en los rankings.

### Cambios de nombre en vivo

El motor no escribe nada al log cuando alguien cambia de nombre a mitad de partida sin
reconectarse — solo eventos de juego (connect, kill, ...). Por eso `cod2:parse-log`,
además de leer el log, también consulta `status` por RCON en cada corrida
(`syncLiveNames()`) y actualiza el nombre de cualquier jugador conectado ahora mismo,
sin importar si generó algún evento en el log.

## Multi-servidor

`servers` (tabla) reemplaza la config fija de `.env`/`config/cod2.php` — cada fila
tiene su propio `log_path`, credenciales RCON (`rcon_password` encriptado con el
cast `encrypted` de Laravel), IP/puerto público, contraseña de conexión opcional
(`join_password`, para servidores con clave). Administrable desde
`/adm_cod2/servers`.

Los jugadores (`players`) son **globales**, no por servidor — el HWID es del hardware,
no del servidor. Las estadísticas sí son por servidor: `player_server_stats` (totales
por servidor) y `player_map_stats` (totales por servidor+mapa). `players.kills_total`
etc. son el acumulado de por vida across todos los servidores.

**Si algún día se agrega un servidor en otra VPS** (no esta misma): el parser hace
`fopen()` directo al `log_path`, así que necesita que el archivo esté en este
filesystem. Para un servidor remoto habría que sincronizar el log primero (rsync por
SSH antes de parsear, o un agente que empuje líneas por HTTP) — no implementado, solo
diseñado en conversación.

## Mapas: nombres bonitos e imágenes

`app/Support/MapCatalog.php` tiene el catálogo mapa→nombre bonito. Las versiones
"parche comunitario" de un mapa (`mp_burgundy_fix`, `mp_dawnville_fix`, etc.) se
normalizan al mapa base (`MapCatalog::normalize()`, quita sufijos `_fixN`/`_vN`) antes
de buscar el nombre — así que un mapa nuevo con sufijo `_fix` que no esté en el
catálogo cae al fallback genérico (nombre generado del código), pero uno que SÍ está
en el catálogo (como Burgundy) muestra el nombre correcto automáticamente.

Las imágenes de mapa se suben a mano desde `/adm_cod2/maps`. Se guardan en
`storage/app/public/maps/{código}.{ext}` y se sirven vía el symlink `public/storage`
(`php artisan storage:link`, ya corrido en el VPS). Si no hay imagen subida, el widget
usa un degradado de color generado determinísticamente del código de mapa.

**Están versionadas en el repo a propósito (2026-08-15).** La decisión original era
NO commitearlas (evitar reproducir assets con copyright de Activision en el repo) —
se revirtió a pedido explícito del dueño, una vez que subió capturas para todos los
mapas, para que una instalación nueva en otro servidor ya las tenga sin tener que
volver a subir cada una a mano. `storage/app/public/.gitignore` (el `.gitignore` por
defecto de Laravel ahí ignora *todo* el directorio) tiene una excepción específica
para `maps/`. Si se sube una imagen nueva desde el panel admin, hay que acordarse de
`git add`la a mano — el upload en sí no la agrega al repo, solo la deja en el
filesystem del VPS.

## GeoIP y banderas de país

Las tablas de jugadores del sitio muestran la bandera del país de origen (según la
última IP vista por RCON). Resumen de cómo funciona, para no tener que releer todo
el historial de commits.

### Fuente de datos: DB-IP, no MaxMind

El plan original era usar `geoip2/geoip2` (ya en `composer.json`) con la base
GeoLite2 de MaxMind. Se probaron **cuatro license keys distintas** de la cuenta
MaxMind 1391854, todas fallando con "Invalid license key" / "could not be
authenticated" — confirmado tanto con curl manual como con el binario oficial
`geoipupdate` v6.1.0, incluso probando una key recién generada al instante. Es un
problema del lado de la cuenta MaxMind (verificación/EULA pendiente, algo que solo
su soporte puede destrabar) — no vale la pena seguir generando keys nuevas sin
resolver eso primero.

En su lugar se usa **DB-IP Country Lite** (`https://db-ip.com`, licencia CC BY 4.0,
sin cuenta ni API key, publica una edición nueva cada mes). Usa el mismo formato
`.mmdb` que GeoLite2, así que `app/Services/GeoIp.php` no necesitó cambiar su
lógica de lectura — solo el path del archivo local
(`storage/app/geoip/country.mmdb`, nombre deliberadamente genérico, sin
"GeoLite2" ni "dbip", por si se vuelve a cambiar de proveedor más adelante).

Si algún día se resuelve el problema de la cuenta MaxMind y se quiere volver a
GeoLite2 (algo más preciso que DB-IP Lite en general), basta con reemplazar
`storage/app/geoip/country.mmdb` por el `.mmdb` de MaxMind con el mismo nombre de
archivo — el código no distingue la fuente.

### Actualización mensual automática

`app/Console/Commands/UpdateGeoIp.php` (`geoip:update`) descarga la edición del mes
desde `download.db-ip.com/free/dbip-country-lite-{YYYY-MM}.mmdb.gz`, la
descomprime, y solo reemplaza el archivo si la descarga fue exitosa y de un tamaño
razonable (nunca deja el sitio sin base de datos por una descarga fallida a mitad
de mes). Corre vía `Schedule::command('geoip:update')->monthly()` en
`routes/console.php` — el cron de Laravel (`php artisan schedule:run`) ya corre
cada minuto en este VPS para otros comandos, así que no hizo falta tocar el
crontab del sistema.

### Cómo se captura el IP de cada jugador

El IP **no viene del log** (`Kill;`/`Connected;` no lo traen) — solo se conoce vía
RCON `status`, que `cod2:parse-log` ya consultaba cada minuto para
`syncLiveNames()` (sincronizar nombres de jugadores conectados). Se agregó una
columna `players.ip` (migración `2026_08_12_010000_add_ip_to_players.php`) que se
actualiza en cada corrida para cualquier jugador conectado en ese momento — así que
el país de un jugador solo se conoce si estuvo conectado *después* de 2026-08-12
(cuando se agregó esto). Jugadores que no han vuelto a conectarse desde entonces no
tienen `ip` y por lo tanto no muestran bandera hasta que vuelvan a jugar.

### Por qué banderas como imagen, no emoji

`GeoIp::flagEmoji()` (el método original) devuelve el emoji Unicode de bandera (dos
"regional indicator symbols"). Se ve bien en iOS/Android/macOS, pero **Windows no
tiene glifo para esos pares** — Chrome/Edge en Windows cae al *fallback* de
mostrar el código de dos letras como texto plano en vez de la bandera (confirmado
por el dueño con capturas: celular sí, PC con Windows no).

`GeoIp::flagIconHtml($isoCode)` es el reemplazo — devuelve un `<img>` que apunta a
`flagcdn.com` (SVG, gratis, sin API key). Es HTML crudo: hay que usarlo con
`{!! !!}` en Blade, no `{{ }}`. Tiene ancho y alto **fijos** (no solo alto con
`width:auto`) porque cada bandera real tiene una proporción oficial distinta
(EE.UU. ~1.9:1, México ~1.75:1, Colombia ~1.5:1) — con solo el alto fijo, las
banderas más "anchas" se veían visiblemente más grandes que las demás.
`object-cover` recorta cada SVG para llenar la misma caja sin importar el país.

### Dónde aparece

Todas las tablas de jugadores del sitio: dashboard (jugadores conectados y top
jugadores), `/ranking` (tabla general y paneles Axis/Allies), `/partidas/{id}`
(tabla general y paneles Axis/Allies), consola de admin, y las páginas de
`/especialidades` (granadas, headshots, fuego amigo, suicidios, eficiencia, mapas
ganados, reyes de mapa, racha de mapas, horas jugadas, actividad reciente, ranking
por arma, rivalidades). La única página dedicada exclusivamente a esto es
`/paises` (`SpecialtyController::countries()`), que agrupa a todos los jugadores
con IP conocida por país y lista sus nombres completos, no solo un top.

### Atribución de DB-IP

Su licencia CC BY 4.0 pide atribución visible. Se agregó un link en el footer ("IP
Geolocation by DB-IP") y luego se quitó a pedido explícito del dueño (2026-08-12)
— es una decisión consciente, no un olvido. Si en algún momento importa el
cumplimiento estricto de la licencia, habría que reconsiderarlo.

## Chat y eventos de partida (2026-08-13)

`games_mp.log` trae muchos más tipos de evento de los que se parsean para
kills/rondas. Dos tablas nuevas los capturan:

- **`chat_messages`** — solo chat público (`say;`), no de equipo (`sayteam;`
  se descarta a propósito). Formato: `say;<guid>;<slot>;<name>;<mensaje>`
  (`ParseCod2Log::recordChat()`). Dos gotchas ya resueltos ahí: el juego
  antepone un byte de control (`0x15`, ícono del globo de diálogo) a cada
  mensaje que hay que quitar, y los acentos llegan en Windows-1252 (no
  UTF-8) — `mb_convert_encoding()` solo cuando el string no es UTF-8 válido
  ya, para no tocar mensajes que sí vienen bien. Se muestra con un botón
  "💬 Chats" en `/partidas/{id}` (solo aparece si esa partida tiene mensajes),
  usando el nombre con color guardado del jugador (`Player::last_name`) en
  vez del nombre plano de la línea de chat, que no trae códigos `^N`.

  **Backfill histórico:** se intentó reconstruir a qué partida pertenecía
  cada mensaje viejo del log ya parseado, y costó dos intentos fallidos antes
  de acertar — ver bitácora de bugs más abajo (entrada 7) antes de repetir
  este ejercicio para cualquier otro backfill retroactivo basado en posición
  del log.

- **`match_events`** — `event_type`: `halftime`, `overtime`, `match_end`,
  `timeout_call`, `timeout_cancel`, `bash_call`. Los tres últimos traen
  `side`/`name` (formato log `<side>;<name>`, sin guid); los primeros tres
  son marcadores sin jugador asociado. `BASH_CALL;` solo se vio una vez en
  todo el historial — significado exacto sin confirmar, se captura igual por
  si acaso pero no tiene su propia página, solo aparece en la línea de
  tiempo de la partida.

  **`HalfTime;` reemplazó la heurística de "ronda 13 = cambio de bando".**
  Antes se asumía por posición (verificado empíricamente contra 2 partidas
  reales, documentado en la bitácora). Ahora el server manda un evento
  `HalfTime;` explícito — confirmado que fires justo después del
  `RoundEnd;`/`Winners;`/`Score;` de la ronda 12 y antes del `InitGame:` de
  la ronda 13, así que en `MatchController::show()` la ronda de cambio de
  bando se busca como "la primera ronda con `started_at` posterior al
  evento", con la heurística vieja (`$rounds->get(12)`) como *fallback* para
  partidas parseadas antes de este cambio (no se hizo backfill de esto, solo
  aplica hacia adelante).

- **`Damage;`** (cada disparo, no solo bajas) y `Weapon;` (cambios/recogidas
  de arma) se relevaron pero **no se implementaron** — `Damage;` tiene un
  volumen mucho mayor que `Kill;` (10-20x más líneas), decisión de
  costo/beneficio pendiente si en algún momento se quiere precisión real
  (%acierto) en vez de solo bajas.

**Ojo con las dos cosas distintas que se llaman "bash" (2026-08-17).** La
línea de tiempo de `/partidas/{id}` tiene un badge "🥊 Más bash · jugador
(N)" que **no** tiene nada que ver con el evento `bash_call` de arriba —
es el líder de bajas cuerpo a cuerpo (`kills.mod = 'MOD_MELEE'`) de esa
partida, calculado en `KillAggregator::aggregate()` (columna `bash`) y
mostrado por `MatchController::show()` (`$topBash`). El mismo patrón se
usa para `$topHeadshots` y `$topGrenades` — los tres son "quién ganó esta
categoría en esta partida específica", no acumulados de por vida (eso ya
existe aparte en las páginas de especialidades). Los tres badges se
muestran en este orden fijo: Inicio, Cambio de bando, Headshots,
Granadas, Bash (a pedido del dueño, no alfabético ni por importancia).

## Bitácora de bugs encontrados y arreglados (2026-08-09/10)

Vale la pena leer esto antes de tocar el parser — son bugs no obvios que ya costaron
tiempo de debug una vez.

1. **Regex del parser no aceptaba espacios al inicio de línea.** CoD2 rellena el campo
   de tiempo transcurrido con espacios para uptimes bajo 100 minutos (`"  2:37"` vs
   `"247:56"`). El regex estaba anclado a `^\d+`, así que **toda línea con menos de
   100 minutos de uptime se descartaba en silencio** — sin error, sin log, solo se
   perdía. Esto costó dos partidas completas (Burgundy, mitad de wawa) antes de
   encontrarse. Fix: `^\s*\d+:\d+...`. En su momento aplicaba también a
   `WatchConnects.php` (eliminado desde 2026-08-10, ver arriba) — si se agrega otro
   proceso que lea el log línea por línea, revisar que use el mismo regex.

2. **`fgets()` se "atasca" en EOF en procesos de larga duración.** Esto se descubrió en
   `WatchConnects` (eliminado desde 2026-08-10), que abría el archivo una sola vez y
   hacía loop indefinido. Una vez que `fgets()` llega al final del archivo, PHP marca
   un flag interno de EOF que no se resetea solo — el proceso sigue vivo pero nunca
   vuelve a leer nada nuevo, aunque el archivo siga creciendo. PHP **no tiene**
   `clearerr()` (es de C, no está expuesto); el fix es `fseek($handle, 0, SEEK_CUR)`
   después de cada pasada. Sigue siendo relevante si se agrega otro proceso
   long-running que lea el log en loop — `cod2:parse-log` no lo sufre porque abre un
   handle nuevo en cada corrida (proceso corto, vía cron).

3. **`g_logsync 0` en `server.cfg` bufferea la escritura del log.** Con eso en 0, el
   motor puede dejar de escribir al archivo por varios minutos aunque el juego siga
   activo (mapas cambiando, jugadores conectados) — sin ningún error visible.
   Cambiarlo a 1 por RCON en caliente **no alcanza** una vez que el proceso ya está
   trabado en ese estado; hace falta **reiniciar el proceso del gameserver** para que
   tome el cambio. Ya está en 1 tanto en `server.cfg` (persistente) como aplicado en
   caliente. Si vuelve a pasar que el log deja de crecer sin razón aparente, este es el
   primer sospechoso — comparar `stat -c '%s' games_mp.log` en dos momentos.

4. **Atacante/víctima invertidos en `Kill;`.** La validación inicial (un bot matando a
   un jugador real) sugería atacante-primero, víctima-segundo — pero confirmado contra
   dos partidas reales completas (marcador final en pantalla) que es al revés:
   **víctima primero, atacante segundo**. Ya corregido en `recordKill()` — el
   comentario en el código explica el porqué. Si algún día vuelve a haber sospecha de
   esto, la forma de verificarlo es comparar el marcador final in-game (kills/muertes
   por jugador) contra `SELECT attacker_name, COUNT(*) FROM kills WHERE match_id=X
   GROUP BY attacker_name`, no confiar en pruebas con bots (el mod puede tratarlos
   distinto).

5. **Timezone de Laravel en UTC, no en la del VPS.** `config/app.php` traía
   `'timezone' => 'UTC'` por defecto — el sistema operativo del VPS ya estaba bien
   (`America/Guayaquil`, -05), pero eso no afecta a Laravel/Carbon, que tiene su propia
   config. Resultado: toda la web mostraba fechas/horas 5 horas adelantadas. Ya
   corregido (`env('APP_TIMEZONE', 'America/Guayaquil')`). Los datos guardados
   *antes* de este fix (todo el backfill inicial y las primeras pruebas de esta noche)
   quedaron con la hora vieja — no se corrigieron retroactivamente, no vale la pena.

6. **`matches.is_backfilled`** — el backfill inicial (`--from-start` sobre el log
   histórico completo) procesó ~5900 líneas en segundos, así que todo ese historial
   quedó con `started_at`/`ended_at` pegados al momento del backfill, no a cuándo se
   jugó realmente (el log no trae reloj absoluto confiable). Esas partidas se marcan
   `is_backfilled=true` y la UI las muestra en una sección "Historial importado" sin
   fecha, en vez de mostrar una fecha falsa como si fuera real.

7. **Backfill de `chat_messages` por posición en el log — dos intentos fallidos antes
   de acertar (2026-08-13).** Al agregar la tabla de chat, se quiso reconstruir a qué
   partida pertenecía cada mensaje `say;` ya presente en el log (histórico).

   - *Intento 1:* emparejar cada `RoundStart;` del log, en orden, contra las filas de
     `rounds` de la BD, en orden de `id`. Falló porque el log actual **todavía
     contiene el contenido del backfill histórico original** (`--from-start`, el que
     generó las partidas `is_backfilled=true`) — Toujane y Railyard se jugaron tanto
     ahí como en partidas reales posteriores, así que había 196 `RoundStart;` de
     Toujane en el log pero solo 76 rondas reales en la BD para ese mapa. El desfase
     corrió la asignación y mezcló mensajes entre partidas.
   - *Intento 2:* agrupar por cambios de mapa en vez de por ronda individual, y saltar
     el prefijo histórico viejo. Mejor, pero **sin validar contra la BD antes de
     insertar** — una transición de mapa "fantasma" (el log creció entre que se
     diagnosticó el offset y que se corrió el insert, ambos por separado) corrió el
     índice en un punto intermedio y un mensaje terminó en la partida siguiente a la
     correcta (detectado porque el dueño notó a un jugador que "no jugó ese día" en
     el chat de esa partida).
   - *Fix real:* la versión que funcionó calcula los bloques de mapa **y valida cada
     uno contra la BD (mapa + cantidad de rondas) en el mismo script, antes de
     insertar nada** — si algo no cuadra, aborta sin tocar la tabla. La lección para
     cualquier backfill futuro basado en reconstruir posición-en-el-log: nunca
     confiar en un diagnóstico corrido por separado del insert (el log y la tabla de
     partidas siguen cambiando en vivo), y siempre validar (round count, no solo
     orden) antes de escribir.

8. **Marcador final (`GameMatch::final_score`) mal calculado en partidas con rondas
   espurias — arreglado parcialmente (2026-08-13).** El dueño reportó la partida 21
   (Toujane) mostrando "12-10" cuando debía ser "13-9". Diagnóstico:

   - El algoritmo original comparaba **cada ronda contra la ronda 1 fija** para
     decidir a qué roster pertenece (por solapamiento de `winner_guids`) — funciona
     mal en partidas largas donde el roster fue cambiando (conexiones/desconexiones)
     y la ronda 1 deja de ser representativa. Fix aplicado: cada cluster ahora
     compara contra **su propia referencia más reciente**, no contra un snapshot
     fijo (`TeamSideAnalyzer::clusterRoundWinners()`, usado ahora por
     `GameMatch::final_score`, `TeamSideAnalyzer::sideScores()` y
     `winningRosterGuids()` — antes era la misma lógica duplicada 3 veces).
   - Además se agregó un corte: apenas un roster llega a 13 rondas (el umbral real
     de victoria en MR12), se deja de contar — una ronda con `winner_guids` válido
     *después* de ese punto es ruido del ready-up/lobby post-partida (mismo tipo de
     bug que las bajas del aim-trainer, ver `recordKill()`).
   - Estos dos cambios corrigieron 12 de 13 partidas reales (por ejemplo la partida
     13 pasaba de "16-14", imposible en MR12, a "13-12", correcto).
   - **La partida 21 específicamente sigue mostrando "12-10" en vez de "13-9".**
     Investigado a fondo: no es el algoritmo — a una de sus 21 rondas "reales" le
     falta el `winner_guids` correcto en la BD. Contando directamente los
     `winner_guids` guardados, el roster ganador solo tiene 12 rondas, pero la
     propia línea `Score;` del servidor (autoridad final) confirma que llegó a 13 —
     falta una ronda en algún punto del log/parseo de esa noche específica. Además
     hay una ronda extra (`rounds.id=386`) con `winner_guids` del roster perdedor,
     creada varios minutos después de que la partida ya había terminado (¿un
     `RoundStart;` residual antes del cambio a Dawnville?) que tampoco se explicó
     del todo. Encontrarlo exactamente requeriría revisar línea por línea el log
     crudo de esa partida puntual — el dueño decidió dejarlo así por ahora en vez de
     seguir invirtiendo tiempo en un caso único. Si se retoma, arrancar revisando
     los `Winners;`/`RoundStart;` crudos entre las rondas 365 y 386 de esa partida.

   - **Addendum (2026-08-17):** el corte fijo en 13 de arriba estaba mal para
     partidas que van a *overtime real* — un empate 12-12 sigue repartiendo
     `winner_guids` válidos más allá de 13, y el corte los truncaba (confirmado
     en una partida real que terminó 16-13 en OT y se mostraba como 13-12
     falso). `clusterRoundWinners()` ahora corta en el evento `match_end` del
     log cuando existe (la autoridad real de "acá terminó la partida"), y solo
     cae al viejo corte-en-13 como *fallback* para partidas sin ese evento
     (anteriores a 2026-08-13). Validado en vivo contra la partida de Dawnville
     del 2026-08-16 (`match_id=38`, St. Mere Eglise en el catálogo de mapas):
     35 rondas reales, 3 períodos de overtime, marcador final 19-16 — coincide
     exacto con la línea `Score;allies;19;axis;16` + `MatchEnd;` del log crudo
     del servidor, la fuente de verdad de zPAM.
   - También se endureció `sideOfCluster()` (usado por `sideScores()`) para
     ignorar cualquier valor de `attacker_team`/`victim_team` que no sea
     `axis`/`allies` (por ejemplo `spectator`) en vez de contarlo como voto —
     antes podía inflar el conteo de un lado con jugadores que en realidad
     estaban de espectadores en esa ronda.

9. **`StatsRecalculator` borraba `damage_dealt`/`damage_taken`/`bomb_plants`/
   `bomb_defuses`/`mid_round_disconnects` al eliminar una partida desde el panel
   admin (2026-08-16).** Estas columnas de `player_server_stats`/`player_map_stats`
   son acumuladores puros — a diferencia de kills/muertes/headshots, no tienen
   tabla de detalle de la que recalcularse, porque `Damage;`/`Bomb;`/
   `Disconnected;` nunca se guardan línea por línea (ver "Chat y eventos de
   partida" arriba, "no se implementaron"). `recalculateAll()` hacía
   `DB::table(...)->delete()` y reconstruía todo solo desde la tabla `kills`, así
   que esas columnas quedaban en cero para siempre apenas se borraba una sola
   partida — el dueño lo notó porque las páginas de especialistas en
   bombas/daño se quedaron sin datos de golpe. Fix: `recalculateAll()` ahora
   hace `->update([...])` poniendo en cero solo las columnas derivadas de
   `kills` (kills, deaths, headshots, grenade_kills, teamkills, suicides),
   dejando intactos los acumuladores sin respaldo. Los datos ya perdidos en ese
   incidente se recuperaron reprocesando `games_mp.log` completo desde byte 0
   con un script de solo lectura que replica la máquina de estados del parser y
   empareja bloques de rondas contra las partidas sobrevivientes en la BD por
   mapa+gametype+cantidad exacta de rondas (19 de 20 partidas recuperadas
   exactas; la partida 11 tiene un bug histórico de parseo de los primeros días
   del proyecto y no calzó con ningún bloque del log).

10. **El gametype `"strat"` de zPAM creaba partidas falsas de 1 ronda
    (2026-08-16).** `strat` es una fase de planeamiento/estrategia previa a la
    ronda real, no gameplay — pero como sí manda su propio `RoundStart;`,
    `openRound()` la registraba como una partida real de una sola ronda. Fix en
    `ParseCod2Log::processLine()`: si `$pendingGametype === 'strat'` en el
    handler de `RoundStart;`, no abre ronda ni partida, solo limpia el estado.
    Una partida `strat` que ya se había colado a la BD antes del fix (map
    Railyard/Stalingrad) fue borrada manualmente por el dueño desde el panel
    admin — verificado después que `StatsRecalculator` (fix de la entrada 9)
    preservó bien el resto de los datos ante ese borrado.

11. **"Round 0" (la fase de ready-up del mod) también creaba partidas falsas,
    sin importar el gametype (2026-08-24).** Mismo problema de fondo que la
    entrada 10, pero más general: el `_match_info` del `InitGame:` trae
    "Round 0 | MR12 Ready-up" durante el lobby de ready-up (antes de que todos
    confirmen listos) y "Round 1 | MR12" recién en la ronda real — pero el
    fix de `strat` solo excluía ESE gametype puntual, nunca miraba esta
    etiqueta. Confirmado en vivo (match id 89, Toujane SD): un `RoundStart;`
    disparó durante "Round 0" con ambos equipos vacíos (nadie conectado
    todavía), y quedó registrado como una partida real de 1 ronda / 0 kills /
    0 min. Fix: nuevo campo `pending_match_info` en `log_parser_state`
    (persistido igual que `pending_map`/`pending_gametype`, capturado del
    `_match_info` del `InitGame:` más reciente); el handler de `RoundStart;`
    ahora se salta por completo si ese valor empieza con "Round 0",
    independiente del `g_gametype`. Reproducido primero con un test
    automatizado (`tests/Feature/ParseCod2LogReadyUpTest.php`, RED
    confirmado antes del fix) — es el primer test de PHPUnit real que tiene
    el parser, antes todo se verificaba a mano contra el log real (ver el
    resto de esta bitácora). La partida fantasma `id=89` ya existente en
    producción no se borró desde el fix — queda pendiente que el dueño la
    borre a mano desde `/adm_cod2/partidas`, mismo precedente que la
    entrada 10.

12. **Partidas reales pero abandonadas también se colaban en el listado
    (2026-08-24), ya con la entrada 11 desplegada.** Con "Round 0" filtrado,
    el dueño reportó (jugando en vivo, match id=90) que una partida que sí
    arrancó de verdad — pasó el ready-up, tuvo rondas y kills reales — pero
    se abandonó antes de llegar a un resultado real (13 rondas o el evento
    `MatchEnd;` del log) seguía apareciendo en `/partidas` y
    `/adm_cod2/partidas` como si fuera una partida terminada cualquiera.
    Confirmado con el dueño: solo debe listarse una partida cuando "llega a
    un resultado real (13 rondas o evento MatchEnd)". Fix en
    `app/Models/GameMatch.php`:
    - `scopeReachedConclusion()`: 13+ rondas jugadas o un `MatchEvent` con
      `event_type='match_end'` — el mismo evento que
      `TeamSideAnalyzer::clusterRoundWinners()` ya usa para el score final
      (reutilizado, no reinventado).
    - `scopeVisibleInListing()`: lo anterior, pero sin excluir partidas
      todavía en curso (`ended_at` null) — solo oculta partidas que ya
      terminaron (por el timeout de 30 min de `ParseCod2Log`) sin haber
      llegado a un resultado real. Una partida en vivo con pocas rondas
      sigue visible con el badge "en curso" ya existente.

    Aplicado directo en `MatchController@index` (público, `/partidas`). En
    `Admin\MatchController@index` (`/adm_cod2/partidas`) se ocultan por
    defecto también, pero con un toggle "Mostrar incompletas" (query param
    `?incompletas=1`) + badge "incompleta" por fila, para no perder la
    forma de encontrarlas y borrarlas a mano — mismo precedente que las
    entradas 10 y 11, pero ahora con una vía en el propio panel en vez de
    tener que pedirle al dueño que confíe en que la partida sigue en la
    BD. Test `tests/Feature/GameMatchReachedConclusionTest.php` (5 casos,
    TDD) verificado GREEN junto al resto de la suite en un entorno aislado
    antes de desplegar. Verificado en producción con `tinker`: `id=89`
    (entrada 11) e `id=90` (esta partida) quedan `visible=no`; partidas
    reales de 19-25 rondas siguen `visible=yes`. Ninguna de las dos se
    borró de la BD — quedan disponibles para el dueño vía el toggle si
    quiere limpiarlas, pero ya no aparecen en ningún listado.

13. **Los kills de una partida incompleta también sumaban al ranking
    (2026-08-24), seguimiento directo de la entrada 12.** El dueño preguntó
    si esas partidas incompletas afectaban el ranking — sí lo hacían:
    `ParseCod2Log::recordKill()` incrementa `kills_total`/
    `player_map_stats`/`player_server_stats` en vivo, kill por kill,
    apenas se procesa cada línea `Kill;` del log, sin mirar si la partida
    a la que pertenece va a terminar bien o se va a abandonar — ese
    resultado recién se conoce cuando la partida termina, no en el momento
    de cada kill individual. Confirmado con el dueño: tampoco deben sumar.
    Fix:
    - `GameMatch::scopeAbandonedWithoutConclusion()` (nuevo): el inverso
      de `scopeVisibleInListing()` — partidas con `ended_at` puesto que
      nunca llegaron a 13 rondas ni a `MatchEnd;`.
    - `StatsRecalculator::recalculateAll()` (existente, antes solo se
      llamaba tras borrar una partida a mano desde el admin) ahora excluye
      esos `match_id` de las 6 queries que alimentan `kills_total`/
      `player_map_stats`/`player_server_stats`.
    - Nuevo comando `cod2:recalculate-stats`, agregado al scheduler cada
      minuto junto a `cod2:parse-log` (`routes/console.php`) — como el
      resultado de una partida no se sabe al momento del kill, la única
      forma de "descontar" retroactivamente los kills de una partida que
      recién se confirma abandonada es recorrer el scheduler; misma
      cadencia de "hasta 1 minuto de retraso" que ya tiene todo el resto
      del pipeline (ver entrada del cron de `cod2:parse-log` en la
      bitácora de infraestructura, `CLAUDE.md` del directorio de trabajo).
    - TDD: `tests/Feature/StatsRecalculatorExcludesAbandonedMatchesTest.php`
      (4 casos) — kills de partida abandonada quedan en 0/null; kills de
      partida en curso, con 13+ rondas, o con `MatchEnd;`, siguen sumando
      igual que antes. Verificado GREEN junto al resto de la suite en un
      entorno aislado antes de desplegar.

14. **Borrar una partida dejaba sus demos huérfanos (2026-08-24).** El
    dueño preguntó qué pasaba con los demos de una partida al borrarla
    desde `/adm_cod2/partidas`. La respuesta: nada — `demos.match_id` usa
    `nullOnDelete()` (no `cascadeOnDelete()` como sí tienen `rounds` y
    `kills`, ver migración `2026_08_19_130000_add_match_id_to_demos_table`),
    así que el registro y el archivo del demo sobrevivían a la partida,
    solo con `match_id` puesto en `NULL`. El problema real: `/adm_cod2/demos`
    arma su listado agrupando por partida
    (`GameMatch::whereHas('demos')`), así que un demo huérfano quedaba
    invisible ahí — sin ninguna forma de encontrarlo ni borrarlo desde el
    panel, pero siguiendo ocupando espacio en disco indefinidamente.
    Confirmado con el dueño: ajustarlo para que borrar la partida borre
    sus demos también. Fix en `Admin\MatchController::destroy()`
    (`app/Http/Controllers/Admin/MatchController.php`): antes de borrar la
    partida, borra cada demo asociado (archivo vía `Storage::disk('local')`
    + registro), el mismo patrón que ya usaba
    `DemoController::destroyByMatch()` por separado — reutilizado, no
    reinventado. TDD:
    `tests/Feature/Admin/MatchDestroyDeletesDemosTest.php` (nuevo, usa
    `Storage::fake('local')`) — verifica que al borrar la partida
    desaparecen tanto el registro del demo como su archivo. Verificado
    GREEN junto al resto de la suite en un entorno aislado antes de
    desplegar.

15. **Bug real y urgente: `cod2:recalculate-stats` (entrada 13) borraba en
    vivo los kills de una partida que TODAVÍA se estaba jugando
    (2026-08-24), detectado por el usuario jugando en tiempo real
    ("tengo ya 25 kill por que no se cuentan").** Causa raíz: `ended_at`
    **no es una señal estable** de "esta partida ya terminó" —
    `openRound()` la marca después de CADA ronda, en el instante exacto en
    que dispara `RoundEnd;`/`ShutdownGame:`, y la vuelve a poner en
    `null` apenas arranca la ronda siguiente de la misma partida (ver el
    branch "continuing" de `openRound()`, más arriba en este archivo). La
    entrada 13 asumía que `ended_at IS NOT NULL` significaba "la partida
    terminó", tratándolo como una foto fija — pero es un valor que el
    propio parser revierte constantemente mientras la partida sigue en
    curso. Con el server del usuario corriendo con bots (rondas
    completándose cada ~1 minuto por el timeout de ronda, sin intervención
    humana necesaria para que una ronda "termine"), `cod2:recalculate-stats`
    corrió justo en ese hueco de segundos entre una ronda y la siguiente,
    vio `ended_at` puesto, y clasificó la partida completa como
    abandonada — borrando sus 20 kills en vivo (`kills_total` de 20 a 0,
    confirmado con el usuario jugando en el momento exacto).
    - Fix en `app/Models/GameMatch.php`: nuevo `scopeStillCurrent()`, que
      usa `log_parser_state.current_match_id` — el puntero que el propio
      parser mantiene sobre cuál es "la partida que sigo rastreando para
      este server" (actualizado en cada corrida de `cod2:parse-log`,
      línea `$state->update(['current_match_id' => $currentMatch?->id, ...])`).
      Mientras una partida siga siendo `current_match_id`, no puede
      considerarse abandonada sin importar lo que diga `ended_at` en ese
      instante — solo cuando el parser pasa a una partida DISTINTA (cambio
      de mapa real, o el heurístico de 30 min de gap) queda "superada" y
      recién ahí es justo evaluarla. `scopeAbandonedWithoutConclusion()` y
      `scopeVisibleInListing()` (entrada 12) ahora usan este scope.
    - Desplegado de urgencia directo a producción, sin esperar el ciclo
      completo de verificación aislada, porque el usuario estaba perdiendo
      su conteo en tiempo real — restaurado a `kills_total=20` corriendo
      `cod2:recalculate-stats` manualmente apenas se aplicó el fix.
      Después, TDD retroactivo: `tests/Feature/GameMatchStillCurrentTest.php`
      (2 casos) — RED confirmado contra el código anterior en un entorno
      aislado (reproduce el bug exacto: kills de una partida "todavía
      current" se contaban como abandonados), GREEN con el fix, y toda la
      suite (17/18, mismo `ExampleTest.php` preexistente sin relación)
      verificada antes de commitear.
    - **Lección general:** un campo que el propio parser "despausa"
      (`ended_at` → `null` al continuar) nunca sirve como señal final para
      un job periódico externo que corre por separado — hace falta un
      puntero explícito al estado "actual" del parser
      (`current_match_id`), no inferir el estado final desde una columna
      que el mismo parser puede revertir en su próxima corrida.

16. **`cod2:parse-log` sin protección real contra corridas concurrentes,
    causando pérdida silenciosa de kills reales (2026-08-24) — bug en
    parte auto-infligido durante el debugging de la entrada 15.**
    Investigando el reclamo del dueño ("el contador no está en 20, está en
    25, validá el log"), se encontró que la partida real tenía 85 kills
    reales del jugador en el rango de líneas del log correspondiente, pero
    la BD solo tenía 29. `Schedule::withoutOverlapping()`
    (`routes/console.php`) solo protege la invocación del propio
    scheduler — no hace nada contra `php artisan cod2:parse-log` corrido a
    mano por fuera de él. Durante el debugging de la entrada 15 se corrió
    el comando manualmente muchas veces vía SSH mientras el cron real
    seguía corriendo cada minuto en paralelo — una condición de carrera
    real, no hipotética. El proceso "perdedor" de la carrera arrancaba con
    `current_round_id`/`current_match_id` desactualizados (pisados por el
    otro proceso antes de que este leyera el estado), así que cualquier
    kill que ese proceso parseaba caía en el descarte de entre-rondas de
    `recordKill()` (línea ~301: `$currentRound->ended_at && $pendingGametype !== 'dm'`)
    y se perdía en silencio, sin error ni warning. Las líneas del log
    nunca se perdieron (confirmado leyendo el archivo crudo directamente
    con PHP puro, evitando el escapado de shell que complicaba `grep` vía
    SSH) — solo la derivación a la base de datos. Fix:
    `Cache::lock("cod2:parse-log:server:{id}", 120)` (driver `database`, ya
    configurado en `.env`) envolviendo toda la corrida por server en
    `parseServer()`/`parseServerLocked()` — una segunda corrida
    concurrente (sin importar el origen) ahora se salta con un warning en
    vez de competir por el estado. TDD:
    `tests/Feature/ParseCod2LogConcurrencyTest.php` (2 casos) verificado
    GREEN en un entorno aislado. **Pendiente, no resuelto todavía:** los
    ~56 kills reales perdidos de esa partida ya jugada no se recuperaron
    — técnicamente posible con un backfill puntual ya que las líneas
    siguen intactas en el log, pero no se hizo por el riesgo de duplicar
    datos si no se hace con cuidado; ofrecido al dueño, pendiente su
    decisión.

17. **El marcador final y el ganador no aparecían en la página de una
    partida jugada 1 humano vs bots (2026-08-24), mismo día que la
    entrada 16.** Causa raíz en `TeamSideAnalyzer::clusterRoundWinners()`
    (`app/Support/TeamSideAnalyzer.php`): los bots siempre reportan
    `guid=0` (indistinguibles entre sí, ver comentario ya existente en
    `ParseCod2Log::upsertPlayer()`) — con bots llenando ambos lados de una
    partida 1v(bots), el `guid 0` aparece en TODAS las rondas del
    `winner_guids`, sin importar qué roster ganó esa ronda. El algoritmo
    de clustering por overlap de guids (diseñado para partidas humano vs
    humano, donde cada roster tiene guids reales y únicos) nunca pudo
    separar los dos rosters porque los ceros compartidos hacían que
    CUALQUIER ronda "solapara" con la referencia del cluster A — el
    cluster B nunca recibía una sola ronda, y la función devolvía `null`
    entero (marcador y ganador desaparecían de la página de la partida,
    aunque los datos de rondas/kills eran reales y correctos). Fix: los
    guids reales (excluyendo 0) son ahora la única señal de clustering;
    una ronda ganada enteramente por bots (sin ningún guid real en el
    `winner_guids`, ej. el roster rival ganó esa ronda) se asigna al
    roster opuesto al de la última ronda clasificada, ya que solo hay un
    ganador posible por ronda. TDD:
    `tests/Feature/TeamSideAnalyzerBotClusteringTest.php` (2 casos,
    incluye guardia de regresión explícita para partidas humano-vs-humano
    con patrón de rondas intercalado, no en bloque, para no disparar el
    corte de "13 rondas" antes de tiempo) verificado GREEN junto al resto
    de la suite. Verificado en producción real: `final_score` de la
    partida pasó de vacío (`null`) a `13-1`.

18. **El marcador y el desglose axis/allies desaparecían de una partida en
    curso mientras un roster la estaba barriendo sin perder ninguna ronda
    todavía (2026-08-24), seguimiento directo de la entrada 17.** Reportado
    en vivo por el dueño en una partida real de Burgundy (`match_id=99`):
    la página mostraba "en curso · 3 ronda(s)" sin marcador, aunque las 3
    rondas ya tenían `winner_guids` reales y válidos. Causa:
    `TeamSideAnalyzer::clusterRoundWinners()` exige que los dos clusters
    (A y B) tengan al menos una ronda para devolver algo — pero un roster
    que todavía no perdió ninguna ronda (3-0 hasta el momento) no deja
    ningún guid real del roster rival para identificarlo, así que el
    cluster B se queda en 0 legítimamente (no es un bug de clustering, es
    falta de señal) y la función devolvía `null` entero, mismo síntoma que
    la entrada 17 pero con una causa distinta. Fix en
    `app/Support/TeamSideAnalyzer.php`:
    - `clusterRoundWinners()` ya no exige que ambos clusters tengan
      score > 0 — cluster A siempre tiene al menos 1 ronda (la primera
      ronda lo inicializa), así que un cluster B en 0 es información
      válida ("3-0"), no una razón para esconder el resultado.
    - `sideScores()`: cuando el cluster B no tiene ningún guid real
      todavía, `sideOfCluster()` no tiene con qué votar — pero como solo
      existen dos lados posibles, una vez que se conoce el lado del
      cluster A (por sus kills reales), el lado B es directamente el
      opuesto, sin necesitar ningún voto.
    - `winningRosterGuids()` no necesitó cambios — ya manejaba bien un
      cluster en 0 (`$clusters['A']['score'] === $clusters['B']['score']`
      simplemente no es cierto cuando uno es 0 y el otro no).
    TDD: `tests/Feature/TeamSideAnalyzerUndefeatedRosterTest.php` (3 casos:
    clustering con roster invicto, `winningRosterGuids()` con el mismo
    escenario, y `sideScores()` infiriendo el lado opuesto) — RED
    confirmado contra el código anterior en un entorno aislado del VPS
    (`/root/cod2-dev-test/`, `composer install` + SQLite in-memory de
    `phpunit.xml`, nunca contra la base de datos de producción), GREEN
    con el fix, y toda la suite (24/25, mismo `ExampleTest.php`
    preexistente sin relación) verificada antes de desplegar.

19. **`/partidas` y `/adm_cod2/partidas` mostraban partidas reales de otro
    gametype, no solo Search & Destroy (2026-08-28).** El dueño reportó una
    partida de hoy, mapa Burgundy, que "no debería aparecer" — resultó ser
    `match_id=111`, gametype `hq` (Headquarters), 70 kills reales y un
    evento `match_end` real (15 minutos jugados de verdad, no una partida
    fantasma tipo entradas 10-12). El pug prioriza SD desde el inicio del
    proyecto, pero `scopeVisibleInListing()`/`scopeReachedConclusion()`
    nunca filtraron por gametype — solo validaban "¿esto es un resultado
    real?" (13+ rondas o `MatchEnd;`), y un HQ real pasa esa validación
    igual que un SD real. Confirmado con el dueño: ocultar cualquier
    gametype que no sea `sd` en ambos listados.

    Fix a nivel controller, no en el scope del modelo — `visibleInListing()`/
    `reachedConclusion()` los siguen usando otros consumidores (
    `StatsRecalculator`, `current_match_id`, etc.) que no deben filtrar por
    gametype, solo la vista de listado:
    - `MatchController::index()` (público) — `->where('gametype', 'sd')`
      tanto en la query de partidas normales como en la de
      `is_backfilled=true` ("Historial importado").
    - `Admin\MatchController::index()` — mismo filtro, pero reusando el
      toggle existente "Mostrar incompletas" (`?incompletas=1`) como vía de
      escape: con el toggle apagado se oculta cualquier gametype que no sea
      `sd` (además de las incompletas, como ya hacía); con el toggle
      prendido se ven todos los gametypes sin filtrar, mismo precedente que
      las entradas 10-12 para poder encontrar y borrar una partida así a
      mano sin tener que ir directo a la base de datos. La partida
      `id=111` no se borró — sigue en la BD, visible solo con el toggle.

    TDD: `tests/Feature/MatchListingSdOnlyTest.php` (2 casos: listado
    público oculta HQ, listado admin lo oculta por default y lo muestra con
    el toggle) — verificado GREEN junto al resto de la suite (197/199, las
    2 fallas son `ExampleTest.php` y `CountriesSeasonTest.php`, ambas
    preexistentes y reproducidas también contra el código sin este fix,
    sin relación) en el entorno aislado del VPS (`/root/cod2-dev-test/repo/`,
    sincronizado con `git archive HEAD | ssh ... tar -x` para eliminar
    staleness de archivos sueltos de sesiones anteriores, en vez de copiar
    archivo por archivo a mano).

## Subida automática de demos por HWID (2026-08-19)

Al terminar una partida SD, el cliente CoD2x de cada jugador sube automáticamente su
demo (`.dm_1`) al panel, identificado por HWID (no por cuenta/UUID de ningún sistema
de match externo). Se puede ver y descargar en `/demos` (público) y administrar en
`/adm_cod2/demos` (borrar, ver tamaño total, configurar retención).

**IMPORTANTE — esto todavía NO está en ningún repo git.** Todo lo de esta sección se
hizo trabajando directo sobre el VPS (sesión de Claude Code por SSH), no en la máquina
donde normalmente se desarrolla y desde donde corre `deploy.sh`. Los archivos Laravel
listados abajo existen solo en el filesystem del VPS — hay que traerlos a la máquina
de desarrollo y comitearlos antes de que el próximo `deploy.sh` (que hace `git archive
HEAD | tar -x`, ver sección "Deploy") los pise con lo que haya en git. El código del
mod zPAM (ver más abajo) ni siquiera es parte de este repo — vive aparte, sin git.

### Cómo funciona el contrato de subida (CoD2x)

El demo se graba **en la PC de cada jugador**, no en el server. Reconstruido leyendo
`src/mss32/demo.cpp` del repo público `callofduty2x/CoD2x`:

- El server (GSC) le indica al cliente el nombre del demo (`cl_demoAutoRecordName`) y
  la URL de subida (`cl_demoAutoRecordUploadUrl`) vía `setClientCvar2`. El endpoint
  **no está hardcodeado en el cliente ni en ningún archivo de config del jugador** —
  vive en el código del mod, en `_record.gsc::execRecording()`:
  ```
  url = "https://cod2.4livepro.com/api/demos/upload/" + self getHWID() + "/";
  self setClientCvar2("cl_demoAutoRecordUploadUrl", url);
  ```
  Si el día de mañana cambia el dominio/endpoint, este es el único lugar a tocar (y
  reconstruir/desplegar el `.iwd`, ver "Cambios en el mod zPAM" abajo).
  **Se evaluó usar la IP directa (`167.148.33.82`) en vez del dominio, para evitar
  pasar por Cloudflare** (el dominio está proxeado — ver "Favicon cacheado por
  Cloudflare" más abajo) **pero se descartó (2026-08-19):** Apache en este VPS rutea
  por virtual host según el header `Host`, y el vhost por defecto (el que responde
  si no hay `Host` que matchee ningún `ServerName`/`ServerAlias`) es
  `monitor.4livepro.com`, no `cod2.4livepro.com` — pegarle a la IP cruda hubiera
  caído en el sitio equivocado. Quedó con el dominio.
- Cuando el cliente arranca a grabar, escribe un archivo marcador
  `demos/<nombre>.dm_1.upload` con la URL completa (`uploadUrl + nombreDelDemo`, sin
  extensión) — esto queda fijo desde ese momento, aunque el server cambie el cvar
  después.
- Al dejar de grabar (fin de partida, `/quit`, o desconexión), el cliente hace un
  **`POST` con el `.dm_1` crudo en el body** (sin multipart, sin Content-Type) a esa
  URL. Espera **200/201/409** para borrar el marcador y darlo por subido; cualquier
  otro código reintenta hasta 3 veces (el contador de reintentos vive en memoria del
  proceso del juego, así que se resetea si el jugador reabre el juego — un marcador
  que falló 3 veces en una sesión vuelve a reintentar en la siguiente).
- `/quit` en consola está parchado por CoD2x a propósito: si hay un demo pendiente de
  subir, pausa el cierre del juego hasta que la subida termine (`cmd_quit()` en
  `demo.cpp`). No hace falta hacer nada del lado del mod para esto.
- El nombre del demo puede tardar unos segundos en generarse tras el fin de la
  partida — `_matchinfo.gsc::clear()` (que dispara `stopRecordingForAll()`) corre
  desde un polling loop (`waitForPlayerOrClear()`) que chequea cada 5-15s, no es
  instantáneo. Confirmado en vivo varias veces durante las pruebas — es esperable, no
  un bug.

### Cambios en el mod zPAM

**No están en git.** Viven en el VPS en `/root/zpam_test/extract10/` (la copia
desempaquetada del `.iwd` más reciente — hay `extract`, `extract2`...`extract10` de
iteraciones previas de desarrollo, `extract10` es la vigente). El `.iwd` final se
arma con `zip -r -X -D` desde adentro de esa carpeta (sin entradas de directorio
extra, para calzar con el formato del `.iwd` original) y se copia a:

- `/home/gameserver/1.3/puG/main/zpam408.iwd` (el que carga el server)
- `/var/www/html/cod2/main/zpam408.iwd` y `/var/www/monitor.4livepro.com/cod2/main/zpam408.iwd`
  (mirrors de descarga rápida — `sv_wwwBaseURL` en `server.cfg` apunta ahí; si no se
  actualizan estos dos junto con el del server, los clientes se traban descargando el
  mod viejo al conectar — pasó una vez, ver conversación del 2026-08-19)

Backups de cada `.iwd` reemplazado en `/root/backups/zpam/`.

Dos archivos tocados dentro de `maps/mp/gametypes/_record.gsc`:

1. **`execRecording()`** — antes, la URL de subida solo se armaba si había un "match"
   oficial activado (sistema externo tipo fpschallenge.eu, con UUID). Se agregó una
   rama `else if (level.gametype == "sd")` que arma la URL con `self getHWID()` para
   el caso de pug libre (sin match activado):
   ```
   url = "https://cod2.4livepro.com/api/demos/upload/" + self getHWID() + "/";
   ```
2. **`getSecureString()`** — se sacaron `#`, `[`, `]`, `{`, `}` del charset permitido.
   Esta función se usa tanto para el nombre de archivo del demo como (ahora) para la
   URL de subida, y un jugador con `#` en el nombre/clan tag (`DESTINATION#ZHAIK`,
   caso real durante las pruebas) cortaba la URL a la mitad — `#` es el separador de
   fragmento en una URL, todo lo que sigue nunca se manda al server. Confirmado en
   vivo: `POST .../DESTINATION` (truncado) en vez de
   `POST .../DESTINATION#ZHAIK_!_1v0_tj_1` (completo).

**`server.cfg`**: `scr_recording` estaba en `0` (grabación apagada). Se cambió a `1`
— sin esto zPAM nunca arranca a grabar, sin importar el resto del código.

### Lado Laravel (este repo — hay que comitear)

**Identidad del jugador — dos IDs distintos, no confundir:**

`self getHWID()` en GSC devuelve un hash hex de 32 caracteres (ej.
`9de59a25f08864a03a55d30cd1318773`). **No es lo mismo** que `players.guid` (el GUID
entero que CoD2x escribe en el log y que usa el parser — ver sección "Identidad del
jugador" más arriba). Reconstruyendo `server.cpp` del repo de CoD2x se encontró la
relación exacta: `guid` es un hash **FNV-1a de 32 bits** del string hex de HWID2
(offset `2166136261`, prime `16777619`, reinterpretado como `int32` con signo).
Implementado en `app/Support/HwidHasher.php` y **confirmado en vivo** contra un
jugador real: mismo HWID hex → mismo `guid` exacto (`17665482`, jugador `zhaiks`).
Esto es lo que permite vincular cada demo a su `Player` sin que el jugador tenga que
loguearse en nada.

**Vínculo demo → partida — inferido por tiempo, con auto-corrección:**

La URL de subida no lleva ningún id de partida (el mod no lo sabe). `match_id` se
resuelve en `app/Support/DemoMatchResolver.php`: la partida no-importada más
reciente con `started_at <= at + 90s`. El margen de 90s existe porque el demo llega
casi al instante (el cliente lo sube apenas termina de grabar) pero
`cod2:parse-log` (cron cada minuto) puede tardar hasta un minuto en crear la fila de
la partida nueva — confirmado en vivo: un demo con `created_at` 12:19:49 tenía que
vincularse a una partida creada recién a las 12:20:02. Por eso también existe
`demos:reconcile-matches` (cron cada minuto, junto a `cod2:parse-log`): re-revisa
demos de los últimos 10 minutos y corrige el `match_id` si para ese momento ya existe
una partida más adecuada.

Si se borra la partida desde el admin (`/adm_cod2/partidas`), el demo vinculado
**no se borra** — `match_id` queda `NULL` (`nullOnDelete` en la FK) y el demo queda
"huérfano": el archivo sigue en disco pero deja de aparecer en `/demos` y
`/adm_cod2/demos` (ambas paginas listan por partida). Decisión explícita del dueño de
dejarlo así (2026-08-19), no tocar.

**Archivos nuevos:**

- `database/migrations/2026_08_19_120000_create_demos_table.php` — tabla `demos`
  (`player_id` nullable FK, `match_id` nullable FK, `hwid` string, `demo_name`,
  `file_path`, `size_bytes`). Ojo: la primera versión de esta migración tenía `hwid`
  como `integer` (supuesto equivocado de que era lo mismo que `guid`) — se
  rehizo/remigró en caliente porque no había datos reales todavía. Si se ve algo raro
  en el historial de migraciones sobre esto, ya está resuelto.
- `database/migrations/2026_08_19_130000_add_match_id_to_demos_table.php`
- `database/migrations/2026_08_19_140000_create_settings_table.php` — tabla
  `settings`, una sola fila (id=1), hoy solo tiene `demo_retention_days` (nullable =
  sin límite).
- `app/Models/Demo.php`, `app/Models/Setting.php`
- `app/Support/HwidHasher.php`, `app/Support/DemoMatchResolver.php`
- `app/Http/Controllers/DemoUploadController.php` — recibe el POST del cliente CoD2x
  en `POST /api/demos/upload/{hwid}/{demoName}` (sin auth, exento de CSRF — el
  cliente del juego no puede autenticarse ni mandar token; ver excepción en
  `bootstrap/app.php` → `validateCsrfTokens(except: ['api/demos/upload/*'])`). Valida
  `demoName` contra el mismo charset que ahora permite `getSecureString()` en el mod.
- `app/Http/Controllers/DemosController.php` — público: `/demos` (partidas con
  demos, tarjetas estilo `/partidas`), `/demos/{match}` (jugadores + descarga),
  `/demos/download/{demo}`.
- `app/Http/Controllers/Admin/DemoController.php` — `/adm_cod2/demos` (listado por
  partida con tamaño total, más el form de retención embebido arriba, estilo
  "Imágenes de mapa"), `/adm_cod2/demos/{match}` (borrar por demo).
- `app/Http/Controllers/Admin/SettingController.php` — solo `update()` (no hay
  página propia, el form vive dentro de `/adm_cod2/demos`).
- `app/Console/Commands/ReconcileDemoMatches.php` (`demos:reconcile-matches`)
- `app/Console/Commands/PruneOldDemos.php` (`demos:prune-old`, corre diario, borra
  archivo+registro de demos más viejos que `settings.demo_retention_days`; no hace
  nada si es `null`)
- `resources/views/demos/index.blade.php`, `resources/views/demos/show.blade.php`
- `resources/views/admin/demos/index.blade.php`, `resources/views/admin/demos/show.blade.php`

**Archivos modificados:**

- `routes/web.php` — rutas públicas y admin de demos (ver arriba)
- `routes/console.php` — agrega `demos:reconcile-matches` (`everyMinute`) y
  `demos:prune-old` (`daily`)
- `bootstrap/app.php` — excepción de CSRF para `api/demos/upload/*`
- `app/Models/GameMatch.php` — nueva relación `demos()`
- `resources/views/layouts/app.blade.php` — link "Demos" en el nav público
- `resources/views/layouts/admin.blade.php` — link "Demos" en el nav admin (no hay
  link de "Configuración" separado — se sacó a pedido del dueño y el control de
  retención se movió adentro de la página de Demos)

**Almacenamiento:** disco `local` de Laravel — en esta versión apunta a
`storage/app/private/` (no `storage/app/` directo, cambió en versiones recientes de
Laravel). Los demos quedan en `storage/app/private/demos/{hwid}/{nombre}.dm_1`, no
públicos (hay que pasar por el controller/ruta de descarga, no están bajo `public/`).

### Otros hallazgos de la sesión (no específicos de demos)

- **Favicon cacheado por Cloudflare.** El sitio está detrás de Cloudflare
  (`server: cloudflare` en las respuestas). Actualizar un archivo estático en
  `public/` (ej. `favicon.png`) no se ve reflejado hasta purgar el caché de
  Cloudflare a mano (dashboard → Caching → Purge Cache) — no hay forma de hacerlo
  desde el VPS/SSH sin las credenciales/API token de Cloudflare, que esta sesión no
  tenía. Si algo se actualiza en `public/` y no se ve el cambio, purgar Cloudflare
  antes de sospechar otra cosa.
- **`public/favicon.png` se reemplazó (2026-08-19)** por uno nuevo (logo con
  estrella + "2") que el dueño subió desde su PC. Es un archivo binario, no
  código — fácil de pasar por alto al reconciliar con git. También toca traerlo al
  repo de desarrollo. `public/favicon.ico` sigue como estaba (0 bytes, no se tocó
  — el layout usa `favicon.png` explícitamente vía `<link rel="icon"
  type="image/png">`, el `.ico` no se usa).

## Panel admin (`/adm_cod2`)

- Login por `username` (no email) — tabla `users` con columna `username` agregada.
- Usuario actual: `adm_cod2` (contraseña la definió el dueño, no está en este archivo).
- CRUD de servidores, subida de imágenes de mapa, consola RCON en vivo (kick, mensaje
  privado/general, cambio de mapa, comando libre) — reconstruye el panel del script
  PHP original de 2007 que se usó como punto de partida, pero limitado a
  administradores autenticados (el script original no tenía login real).
- `bootstrap/app.php` tiene `redirectGuestsTo('/adm_cod2/login')` — si se cambia el
  prefijo de rutas admin, hay que actualizar esto también (es un string, no una ruta
  con nombre, porque corre antes de que el router esté disponible).
- `/adm_cod2/demos` (borrar demos, ver tamaño total por partida, configurar retención)
  — ver sección "Subida automática de demos por HWID" más arriba.

## Deploy

**Política del dueño (2026-08-22): todo cambio de código se documenta en CLAUDE.md
y/o README.md (lo que corresponda) y se despliega al VPS como parte del mismo
trabajo, sin tener que pedir confirmación cada vez.** Esto no cubre acciones
destructivas/irreversibles fuera de lo normal (borrar datos de producción, tocar
`.env`/credenciales, downgrades de dependencias, etc.) — esas siguen requiriendo
confirmación explícita como cualquier otra acción de alto riesgo.

`deploy.sh` — mismo patrón que `desarrollo.4livepro.com`: `git archive HEAD | ssh
iptvwatch "tar -x -C /var/www/cod2.4livepro.com"`, seguido de `composer install`
(opcional, `--composer`), `migrate` (opcional, `--migrate`), y `php artisan optimize`.

**Importante:** el `chown -R www-data:www-data` corre *antes y después* de `optimize`
— `optimize` corre como root (vía SSH) y recrea `storage/framework/views/*` como root,
lo que bloqueaba a Apache/PHP (www-data) para tocar esos archivos y causaba 500s
intermitentes en páginas no visitadas todavía. Si se edita `deploy.sh`, no quitar el
segundo `chown`.

**`tar -x` es aditivo — nunca borra en el VPS un archivo que dejó de estar en el
commit desplegado (2026-08-17).** Si un archivo se elimina en git pero ya se había
desplegado alguna vez, se queda huérfano en el filesystem del VPS para siempre,
invisible a `git status`/`git diff` porque el VPS no tiene `.git`. Pasó dos veces:
`app/Console/Commands/WatchConnects.php` (eliminado de git el 2026-08-10) y
`resources/views/specialties/grenades.blade.php` (eliminado sin querer en el commit
`9400ff7` del 2026-08-11) siguieron funcionando/existiendo en producción como si
nada, generando falsas alarmas al comparar "qué hay en git" contra "qué hay
realmente corriendo". Si se sospecha que el VPS tiene archivos que ya no deberían
existir, no alcanza con mirar el repo — hay que diffear el filesystem real del VPS
contra el árbol de git (`git archive HEAD | tar -t` vs. `find` en el VPS) y borrar
a mano lo que sobre. `deploy.sh` no se tocó para arreglar esto (un `rsync --delete`
sería más seguro que agregarle un `rm -rf` ciego a un script que corre por SSH como
root) — sigue siendo responsabilidad manual por ahora.

**Alguien puede desplegar desde otra máquina sin pasar por esta conversación
(confirmado 2026-08-17).** El dueño clonó el repo en otra computadora, le pidió a
otra sesión de Claude Code que actualizara el frontend, y lo desplegó — esta sesión
no se enteró hasta que preguntó "¿está todo actualizado?" y hubo que diffear
`app/`/`resources/` del VPS contra el HEAD local para encontrar la diferencia. No
hay ningún marcador de "qué commit está desplegado" en el VPS (`git archive` no
manda `.git/`), así que la única forma de detectar esto es esa comparación manual
de filesystem. Si el sitio se ve distinto a lo que dice el git log local, empezar
por ahí antes de asumir que no pasó nada.

## Migración a VPS dedicado (2026-08-24)

Panel + gameserver migrados de `167.148.33.82` (compartido con IPTV/billing de
4LivePro, alias SSH `iptvwatch`/`iptv-vps`) a `151.245.32.43` (exclusivo para COD2).
Mismas specs de RAM (913MB, 1 solo core), pero sin compartir con otros proyectos y
con el stack LAMP corregido — el VPS viejo tenía `mod_php` + `mpm_prefork` (cada
proceso Apache carga el intérprete PHP completo, ~50MB c/u en reposo), reemplazado
acá por `mpm_event` + PHP-FPM. El VPS viejo queda de respaldo indefinido, sin apagarse.

**Todo lo de infraestructura (Apache/PHP-FPM/MariaDB, systemd, sudoers, `ufw`,
timezone del SO) vive solo en el VPS, no en este repo** — mismo precedente que
`/etc/sudoers.d/cod2-panel` y el mod zPAM (ver sus secciones más abajo). Si el VPS se
reconstruye de nuevo, hay que recrear todo esto a mano; los puntos más importantes
(no obvios) quedan documentados acá.

### `mod_php` → PHP-FPM: el gotcha de `mod_proxy_fcgi` con bodies chunked

**El más importante de esta migración, si se repite el patrón de servir esta app
detrás de Apache+FPM en otro lado.** La subida automática de demos (ver más abajo)
dejó de funcionar tras el cambio de stack — no por tamaño de archivo ni por el
código de `DemoUploadController` (sin cambios), sino porque **el cliente CoD2x sube
el `.dm_1` con `Transfer-Encoding: chunked`, sin `Content-Length` fijo de antemano**.
`mod_proxy_fcgi` no bufferiza un body chunked del cliente antes de reenviarlo al
backend FastCGI (PHP-FPM) — sin esto, PHP nunca ve el contenido, `$request->getContent()`
devuelve vacío, y el endpoint responde `422 "Empty body"` (con algo de ruido de un
warning de PHP mezclado, si se compara tamaño de respuesta esperado vs. real). `mod_php`
nunca lo sufría porque no hay salto proxy→FastCGI de por medio.

Reproducible con: `curl -H 'Content-Type:' -H 'Transfer-Encoding: chunked' --data-binary @- URL`
(cualquier endpoint que reciba un body crudo grande sin `Content-Type`/`Content-Length`
fijo va a mostrar el mismo síntoma detrás de FPM sin este fix).

**Fix:** agregar `SetEnv proxy-sendcl 1` dentro de `<IfModule proxy_fcgi_module>` en
`/etc/apache2/conf-available/php8.3-fpm.conf` (el conf que genera el paquete
`php8.3-fpm` de Ubuntu al correr `a2enconf php8.3-fpm`) — fuerza a Apache a
bufferizar el body completo y mandar un `Content-Length` real al backend en vez de
reenviar chunked. Sin esto, cualquier subida de archivo grande sin `Content-Length`
declarado de antemano (no solo demos — cualquier feature futura con el mismo patrón
de upload) va a fallar igual.

### Otros ajustes de PHP/Apache que hay que replicar si se reinstala desde cero

- **`post_max_size`/`upload_max_filesize` en `60M`** (subido de `25M` el
  2026-08-31, ver "Bug real: demos grandes rechazados con 413" más abajo —
  el default de PHP es `8M`, insuficiente ya desde el arranque). El viejo VPS
  ya lo tenía en `25M` en su `php.ini` de `mod_php`; se perdió al pasar a FPM
  porque son archivos `php.ini` distintos (`/etc/php/8.3/apache2/php.ini` vs
  `/etc/php/8.3/fpm/php.ini`) y no hay ninguna automatización que los
  mantenga sincronizados.
- `pm.max_children` del pool de FPM: arrancó en `6` (conservador, calcado del viejo)
  pero ya disparó `WARNING: server reached pm.max_children setting` bajo carga
  liviana de pruebas — subido a `10` (con `~40MB` promedio medido por worker, dentro
  del margen de RAM del VPS). Si vuelve a aparecer ese warning en `php8.3-fpm.log`,
  es la primera señal de que hay que revisar esto de nuevo (no asumir que es otra cosa).
- `memory_limit=256M` (el viejo tenía `-1`, ilimitado — un script colgado podía
  tumbar todo el panel; nunca bajarlo a `-1` de nuevo).
- Timezone del **sistema operativo** en `America/Guayaquil` (`timedatectl
  set-timezone America/Guayaquil` + reiniciar `mariadb`) — Laravel ya fuerza esta
  timezone a nivel de app (`config/app.php`, ver más abajo), pero MariaDB usa
  `time_zone=SYSTEM`, así que cualquier `NOW()`/`CURRENT_TIMESTAMP` nativo de SQL
  queda en la hora del SO si no se ajusta esto — un VPS nuevo sin este paso muestra
  horas desalineadas 100% consistentes con el offset real del datacenter (confirmado
  en vivo: `Europe/London` por default del proveedor, 6 horas de diferencia con
  Guayaquil).

### Bug real: demos grandes rechazados con 413 (2026-08-31)

El dueño reportó que jugadores no lograban subir demos de una partida de
Burgundy jugada el domingo 30 de agosto — el error real era un **413**
(Request Entity Too Large), no algo específico de Burgundy: el mismo error
pegaba en cualquier mapa cuya partida fuera lo bastante larga.

**Causa raíz confirmada en `cod2-error.log`:**
```
PHP Request Startup: POST Content-Length of 27691731 bytes exceeds the
limit of 26214400 bytes
```
`post_max_size`/`upload_max_filesize` seguían en `25M` (`26,214,400` bytes
exactos) desde la migración al VPS nuevo — ver la entrada de arriba, calcado
del valor que ya tenía el VPS viejo en 2026-08-19 cuando los demos reales
rondaban 10-12MB. Los partidos de esta comunidad se alargaron desde
entonces (partidas 8v8/9v9/11v11 de muchas rondas) y varios demos reales ya
estaban rozando el límite antes de romperlo del todo — confirmado en
`demos` de esa misma noche: dos demos de la partida 122 (Dawnville, St.
Mere Eglise) entraron justo por debajo (`25,843,363` y `25,924,602` bytes,
a menos de 300KB del límite) mientras que el resto de los ~11 jugadores de
esa partida (11v11) quedaron con demos de 26-28MB que rebotaron con 413 en
cada uno de sus 3 reintentos automáticos — de ahí que la partida solo
tenga "2 demo(s)" en `/partidas` en vez de una por jugador. Mismo patrón
confirmado también para un demo de Burgundy (`re_Genuine_bg`) y uno de
Toujane (`re_Genuine_tj`) esa misma noche, en sesiones de juego
posteriores (el contador de 3 reintentos vive en memoria del proceso del
juego — ver "Cómo funciona el contrato de subida" más abajo — así que
volvió a fallar 3 veces más cada vez que esos jugadores reabrieron CoD2).

**Fix:** `post_max_size`/`upload_max_filesize` subidos de `25M` a `60M` en
`/etc/php/8.3/fpm/php.ini` (más del doble del pico real visto, `~28MB`, para
no volver a rozar el límite con partidas todavía más largas) +
`systemctl reload php8.3-fpm`. Verificado en vivo con un POST real de 30MB
en chunked (mismo patrón que usa el cliente CoD2x, ver "El gotcha de
`mod_proxy_fcgi` con bodies chunked" más arriba) contra el endpoint real de
producción: `413` antes del cambio → `201` después. Registro/archivo de
la subida sintética de prueba borrados a mano después de confirmar.

**Los demos que ya se perdieron esa noche (los que rebotaron con 413 antes
del fix) no se pueden recuperar desde este servidor** — nunca llegaron a
escribirse en `storage/`, así que no hay ninguna copia server-side de la
que reconstruirlos (a diferencia de un kill perdido por una condición de
carrera, que sigue en el log de texto plano y se puede reprocesar, ver la
bitácora de bugs más arriba, entrada 16). El único lugar donde podrían
seguir existiendo es el disco del propio jugador, si CoD2x no borra el
`.dm_1` local tras agotar los 3 reintentos (sin confirmar — el código
fuente de CoD2x solo documenta que borra el *marcador* `.dm_1.upload` al
confirmar éxito, no qué hace con el demo en sí tras un fallo definitivo).
Nada que hacer retroactivamente del lado del servidor para esa partida
puntual; el fix solo previene que vuelva a pasar.

### Prioridad del gameserver vs. la carga del panel — verificado con carga real, no solo config

El VPS (viejo y nuevo) tiene **1 solo core** — todo compite por el mismo núcleo, así
que la prioridad de `cod2server.service` (`Nice=-20`, `CPUWeight=9500`,
`IOSchedulingClass=realtime`, ver el `.service` en el repo `cod2-server`) no es
cosmética. Se probó en vivo (2026-08-24, server real vacío, sin jugadores): `ab -n
3000 -c 25` contra el panel durante ~41s (MariaDB llegó a ~7.3% CPU, cada worker de
PHP-FPM ~4-5%, saturando el único core disponible), midiendo en paralelo con
`pidstat` el proceso del gameserver y revisando su propio log (`journalctl -u
cod2server.service`, que reporta `Hitch warning` cuando el frame time se degrada de
verdad — señal directa del motor, no una métrica indirecta). Resultado: CPU del
gameserver sin cambio real (1.12% durante la carga vs 1.20% en reposo), **cero**
hitch warnings durante toda la ventana. La config protege al proceso de verdad, no
solo en el papel.

### Plan para cuando se agregue un segundo core: CPU affinity (no aplicado todavía)

**Estado actual (2026-08-30): el VPS sigue en 1 solo core.** Ojo con `nproc --all`
en este VPS — devuelve **128** (un artefacto de virtualización del proveedor, no
el conteo real). La fuente confiable es `grep -c ^processor /proc/cpuinfo` (o
`lscpu`), que sí confirma 1 core. Si se vuelve a dudar cuántos cores tiene la VM,
usar `/proc/cpuinfo`, nunca `nproc` a secas, en este proveedor puntual.

Si el dueño agrega un segundo core, la idea acordada es **CPU affinity** (pinning)
vía systemd, no solo la prioridad relativa que ya existe hoy
(`Nice=-20`/`CPUWeight=9500`, ver sección anterior) — affinity es aislamiento
duro: un proceso pineado a un core nunca se ejecuta en el otro, sin importar la
carga. Con `Nice`/`CPUWeight` el gameserver solo tiene *preferencia*, pero
técnicamente puede correr en cualquier core; con `CPUAffinity` no puede, punto.

**Resultado buscado:**

| | Core 0 | Core 1 |
|---|---|---|
| Sistema operativo (kernel, systemd, cron, journald) | ✅ siempre | ❌ nunca |
| LAMP (Apache, PHP-FPM, MariaDB, queue worker) | ✅ siempre | ❌ nunca |
| Servidores temporales (`cod2-temp@.service`) | ✅ siempre | ❌ nunca |
| `cod2server.service` (gameserver real) | ❌ nunca | ✅ siempre |

**Importante: hacen falta las DOS mitades, no alcanza con pinear solo el
gameserver.** Sin restringir también el resto del sistema, Apache/MySQL igual
pueden terminar corriendo en el core 1 cuando el scheduler de Linux (CFS) decida
mandarlos ahí — por defecto, sin `CPUAffinity` en ningún lado, cualquier proceso
puede correr en cualquier core, se reparte dinámicamente. Fijar solo el
gameserver protege al juego de que nada más lo invada, pero no garantiza que el
core 1 quede *exclusivo* del juego a menos que el otro lado también esté pineado.

**1. Sistema + LAMP → core 0**, en `/etc/systemd/system.conf`:
```ini
CPUAffinity=0
```
Aplica a PID 1 y a cualquier servicio que no lo sobreescriba explícitamente
(Apache, PHP-FPM, MariaDB, queue worker, cron, journald, etc. de una sola vez).
Requiere reiniciar el sistema (o `systemctl daemon-reexec` + reiniciar cada
servicio) para tomar efecto.

**2. `cod2server.service` → core 1**, agregar al bloque `[Service]` del archivo
real en el VPS (`/etc/systemd/system/cod2server.service` — no vive en este repo,
ver "Systemd + sudoers..." más abajo):
```ini
CPUAffinity=1
```
No reemplaza nada de lo que ya tiene (`Nice=-20`, `CPUWeight=9500`,
`IOSchedulingClass=realtime` siguen intactos — `CPUAffinity` decide *en qué
core* corre, esas otras directivas deciden *con qué prioridad* dentro de ese
core). Los índices de core en systemd son 0-based, así que en una VM de 2 vCPUs
`0`/`1` son los dos únicos disponibles.

**Servidores temporales (`cod2-temp@.service`):** dejarlos en el core 0 (junto al
LAMP, heredado del `CPUAffinity=0` de `system.conf`, sin agregarles nada propio)
en vez de compartir el core 1 con la partida real — ya están pensados con menos
prioridad (`CPUWeight=100`, sin `Nice` negativo, `MemoryMax=150M`), pinearlos al
mismo core que el juego real competiría por CPU dentro de ese único núcleo sin
necesidad.

**Después de aplicar, verificar:**
```bash
sudo systemctl daemon-reload
sudo systemctl restart cod2server.service
systemctl show cod2server.service -p CPUAffinity
taskset -pc $(pgrep cod2_lnxded)
```
y en vivo con `htop` (tecla `f` → mostrar por núcleo) o `mpstat -P ALL 1` para
confirmar que el core 1 solo se mueve con el juego.

**A tener en cuenta antes de aplicarlo:** con el LAMP stack completo comprimido
en un solo core, un pico de tráfico real podría saturar ese núcleo más rápido que
hoy (que reparte libremente entre los cores disponibles) — para el volumen actual
de esta comunidad no debería ser problema, pero vale vigilarlo las primeras
semanas. Tampoco es aislamiento 100% perfecto: las interrupciones/hilos del propio
kernel (IRQs de red, etc.) no quedan afectados por `CPUAffinity=0` de
`system.conf`, siguen repartiéndose donde el kernel decida — suficiente para este
caso de uso (evitar que Apache/MySQL le compitan CPU al gameserver), no una
partición perfecta.

Como el resto de la config de systemd/sudoers de este proyecto, esto es un
cambio de infraestructura del VPS, no de código — no viaja con git ni con
`deploy.sh`. Si se reconstruye el VPS algún día, hay que volver a aplicarlo a
mano con lo documentado acá.

### Endurecimiento de seguridad del VPS (2026-08-31)

Auditoría a pedido del dueño ("qué mejoras podemos aplicar a la VM") — sin
hallazgos graves en backups/logs/memoria/MySQL (ya cubiertos en otras
secciones), pero tres gaps reales de seguridad, los tres aplicados en la misma
sesión:

- **37 paquetes desactualizados (28 de seguridad).** `unattended-upgrades`
  está activo y corriendo bien (confirmado en su log, cubre el pocket
  `noble-security`), pero no había llegado a aplicarlos todavía — cerrado a
  mano con `apt-get upgrade` (+ `libgd3` del repo de terceros de PHP, que
  unattended-upgrades nunca toca a propósito por no ser origen oficial de
  Ubuntu). `apache2`/`mariadb`/`php8.3-fpm`/`ssh` se reiniciaron solos
  (`needrestart`) — verificado sitio en `200` y los 4 servicios `active`
  después. **0 paquetes pendientes** al cierre.
- **Sin firewall (`ufw` estaba instalado pero `inactive`).** Solo SSH
  (22), Apache (80/443) y el puerto UDP del juego estaban realmente
  expuestos, y MySQL ya estaba bien atado a `127.0.0.1` — pero sin `ufw`
  cualquier servicio que en el futuro quede mal expuesto (un puerto de
  debug, un bind a `0.0.0.0` por error) no tiene ninguna barrera. Reglas
  explícitas ANTES de activar (nunca al revés, para no perder acceso SSH a
  mitad de camino): `22/tcp`, `80/tcp`, `443/tcp`, `28960/udp` (Pug Latam,
  `servers.connect_port`/`rcon_port`, mismo puerto para juego y RCON en el
  motor id Tech 3), `28990/udp` (rango de `settings.hosted_servers_ports` en
  ese momento) + default `deny incoming`/`allow outgoing`. **Ya había reglas
  viejas cargadas sin activar** (`28970:28971/udp`, un rango de servidores
  temporales previo — ver "Servidores temporales self-service" más arriba)
  — se dejaron intactas (no rompen nada) en vez de tocarlas sin necesidad.
  Verificado acceso SSH y sitio (`200`) con el firewall ya activo antes de
  dar el cambio por terminado.
  **`hosted_servers_ports` cambiado el mismo día a `28950,28970,28980,28990`**
  (pedido del dueño) — el primer intento pidió incluir `28960`, pero ese es
  el puerto real de Pug Latam y el panel lo rechaza a propósito (validación
  agregada 2026-08-25 tras un choque real, ver esa sección) — se reemplazó
  por `28950`. Reglas de `ufw` ampliadas para cubrir los 4 puertos
  (`28970` ya estaba cubierto por el rango viejo `28970:28971`, se agregaron
  `28950/udp` y `28980/udp` sueltos).
- **Sin fail2ban, pese a ~11.000 intentos de login SSH fallidos** en el
  `auth.log` vigente (scaneo constante de bots, esperable en cualquier IP
  pública, pero el volumen es alto). No era un riesgo real de intrusión
  (`PasswordAuthentication no` ya en `sshd_config`, solo entra quien tenga
  la clave privada), pero sí ruido/consumo constante — coincide con el
  volumen de `auth.log`/`btmp` ya detectado en la sección de espacio en
  disco más abajo. Instalado con una sola jaula (`[sshd]`,
  `/etc/fail2ban/jail.local`): **5 intentos fallidos en 10 minutos → ban de
  1 hora**, backend `systemd` (lee el journal directo, no el archivo de
  log). No afecta el acceso normal por clave — solo cuenta intentos
  *fallidos*, y una conexión válida por clave nunca cuenta como uno.

Los tres cambios son infraestructura del VPS, no código — mismo precedente
que systemd/sudoers/CPU affinity: no viajan con git ni con `deploy.sh`, hay
que rehacerlos a mano si el VPS se reconstruye (los paquetes se resuelven
solos con `apt upgrade`, pero las reglas de firewall y `jail.local` de
fail2ban sí hay que recrearlas — el contenido exacto queda documentado
abajo, **`ufw` ya no es la herramienta real usada para esto**, ver la
entrada siguiente).

### Incidente real: `iptables -F` tumbó el firewall y cortó el acceso — `ufw` abandonado por `iptables` directo (2026-08-31)

**Mismo día que se activó `ufw` (entrada anterior).** El dueño corrió
`iptables -F` a mano en el VPS (sin relación con este proyecto, explorando
el sistema) — y tumbó el sitio y el acceso SSH durante varios minutos.

**Causa raíz:** `ufw default deny incoming` no es solo una lista de reglas
— también fija la **política** del chain `INPUT` en `DROP` a nivel
`iptables`. `iptables -F` borra las reglas de todos los chains pero *nunca*
toca la política de los chains built-in (`INPUT`/`OUTPUT`/`FORWARD`) — así
que después del flush, `INPUT` quedó con política `DROP` y **cero reglas**,
osea bloqueando literalmente todo el tráfico entrante, SSH incluido. El
sitio devolvía `522` (Cloudflare no podía llegar al origen).

**Sin ningún acceso remoto posible** (SSH es el único camino que tiene esta
sesión hacia el VPS, y era exactamente lo que estaba bloqueado) — la única
via de recuperación fue la consola VNC/web del proveedor de hosting, que no
pasa por la red del servidor y por lo tanto no la afecta el firewall.
Recuperado a mano ahí con `iptables -P INPUT ACCEPT` (ver más abajo por qué
no alcanzó con reiniciar `ufw`).

**Segundo hallazgo, más grave: `ufw enable` (y `systemctl restart ufw`) es
poco confiable en este VPS puntual para reconstruir el firewall desde cero
via SSH.** Se intentó reconstruirlo dos veces distintas (`systemctl restart
ufw` primero, `ufw disable && ufw --force enable` despues) y **las dos
veces volvió a cortar el acceso SSH**, exactamente el mismo síntoma que el
incidente original. Diagnóstico: el script de `ufw` aplica la política
`DROP` en `INPUT` en algún punto de su secuencia interna *antes* de que la
regla que conecta ese chain con las cadenas propias de `ufw`
(`ufw-before-input`, `ufw-user-input`, etc., donde vive el `ACCEPT` de
puerto 22) termine de insertarse — no es atómico. Si esa ventana coincide
con tráfico real de la sesión SSH en curso, la conexión muere ahí mismo, y
el resto del script nunca termina de aplicarse correctamente. Reproducido
2 de 2 veces — no es una casualidad de una corrida puntual.

**Técnica usada para recuperarse sin depender de la consola cada vez —
"dead man's switch":** antes de cada intento de reconstruir el firewall,
se armó un proceso en background en el propio VPS (`nohup bash -c 'sleep
N && <comando que abre todo de nuevo>' & disown`) que se auto-ejecuta a los
N segundos *sin importar si la sesión SSH sigue viva* — si el intento de
reconstruir el firewall cortaba el acceso, el VPS se autocuraba solo
minutos después sin volver a pedirle al dueño que entre a la consola. Se
cancela (`kill` del PID guardado) si el intento salió bien. Vale la pena
reusar este patrón para **cualquier cambio remoto que pueda cortar el
acceso que se está usando para aplicarlo** (firewall, SSH config, reglas de
red en general) — mucho más barato que una vuelta a la consola del
proveedor.

**Fix real, evitando el bug de `ufw` en vez de perseguirlo:** se abandonó
`ufw` como mecanismo de aplicación (queda **deshabilitado y enmascarado**,
`systemctl mask ufw`, para que nadie lo reactive sin querer y repita el
bug) y se reconstruyó el firewall con `iptables` crudo, en un orden
deliberadamente seguro para hacerlo por SSH sin riesgo de corte:

1. Con la política todavía en `ACCEPT` (sin riesgo), agregar TODAS las
   reglas `ACCEPT` necesarias primero: loopback, `RELATED,ESTABLISHED`,
   `22/tcp`, `80/tcp`, `443/tcp`, `28960/28950/28970/28980/28990 udp`
   (mismos puertos que ya se habían definido con `ufw`), `icmp`.
2. **Recién al final**, con esas reglas ya en el chain, cambiar la
   política: `iptables -P INPUT DROP` (y `-P FORWARD DROP`, no se usa
   forwarding en este VPS; `OUTPUT` se deja en `ACCEPT`, el servidor
   necesita salir libremente para `apt`/`geoip:update`/etc.). Para cuando
   la política pasa a `DROP`, la regla `ACCEPT` de `22/tcp` ya existe y
   matchea primero — no hay ninguna ventana sin cobertura.
3. Persistido con `iptables-persistent`/`netfilter-persistent save` (paquete
   nuevo, instalado en esta sesión) — sin esto, las reglas de `iptables`
   puro no sobreviven un reinicio (a diferencia de `ufw`, que sí se
   encarga de eso solo).

Verificado con una conexión SSH **nueva** (no la misma sesión) después de
aplicar la política `DROP` final — entró sin cortes. Sitio en `200`,
los 6 servicios (`apache2`/`mariadb`/`php8.3-fpm`/`ssh`/`cod2server`/
`fail2ban`) `active`, `fail2ban` ya baneando IPs reales (7 baneos totales
a los pocos minutos).

**IPv6 no se replicó a propósito** — `ip -6 addr show scope global` no
devuelve ninguna dirección pública, este VPS no tiene conectividad IPv6
real expuesta, así que no hay superficie que proteger ahí (quedaron
cadenas `ufw6-*` viejas y vacías de la config anterior, inofensivas, no se
tocaron).

**Estado final:** mismo conjunto de puertos permitidos que la config de
`ufw` que se había armado antes (22/80/443/juego), pero aplicado con
`iptables` puro.

**`ufw` purgado del todo (2026-08-31, mismo día, a pedido del dueño)** —
primero quedó deshabilitado/enmascarado como mitigación rápida, pero
después se sacó por completo: `apt-get purge -y ufw` (paquete y config),
más limpieza manual de lo que el purge no toca porque vive en runtime del
kernel, no en archivos — las 18 cadenas `ufw-*` vacías que quedaron
huérfanas en `iptables` (`iptables -X` una por una, todas con 0
referencias, seguro) — y `/etc/ufw/` (solo quedaba `applications.d`, un
punto de integración de otros paquetes tipo Apache, inerte sin `ufw`
instalado). Verificado `dpkg -l | grep ufw` sin resultados, sitio en `200`
y reglas de `iptables` intactas antes y después de cada paso.

**No hay ningún firewall de alto nivel instalado en este VPS — solo
`iptables` puro.** Si en algún momento hace falta agregar/sacar un puerto,
hacerlo con `iptables -A/-D INPUT ...` directo (respetando el mismo orden
seguro: agregar ACCEPTs antes de que la política DROP los necesite, nunca
al revés) + `netfilter-persistent save` para que sobreviva un reinicio. No
reinstalar `ufw` para esto sin resolver primero el problema de orden de su
script documentado arriba.

### `deploy.sh` apuntaba al VPS viejo — corregido (2026-08-31)

`SSH_HOST="iptvwatch"` en `deploy.sh` apuntaba al VPS compartido viejo
(respaldo, ya no es producción) en vez del VPS nuevo (`151.245.32.43`).
Cambiado a `SSH_HOST="cod2-vps"` — cada máquina de desarrollo necesita esa
entrada en su `~/.ssh/config` (`HostName 151.245.32.43`, `User root`,
`IdentityFile` a la clave correspondiente) para que `./deploy.sh` funcione.

**Ojo con esta sección si se la vuelve a leer en el futuro:** en algún
momento del 2026-08-31 el `CLAUDE.md` copiado a mano en el VPS
(`/var/www/cod2.4livepro.com/CLAUDE.md`, no el de este repo) decía que este
fix ya estaba aplicado con un alias `cod2-vps-new` — pero `deploy.sh` en
git nunca había cambiado (confirmado: seguía con `iptvwatch`, sin tocar
desde el commit inicial del repo, y sin commits nuevos en GitHub). Alguien
hizo (o dijo haber hecho) el cambio directo en el VPS, sin pasar por git —
mismo patrón ya documentado en "Alguien puede desplegar desde otra máquina
sin pasar por esta conversación" más abajo. El dueño lo notó comparando
este archivo contra lo que había en el VPS. Esta vez el fix se hizo de
verdad en el repo (este commit), así que viaja con git y no depende de que
cada máquina lo reaplique a mano.

## Auditoría de admin y reinicio de servicio (2026-08-19)

Dos mejoras al panel admin, a pedido del dueño tras una sesión de pruebas donde
varias partidas/demos se borraron y hubo que rastrear el access log de Apache a
mano para entender qué había pasado.

**Tampoco está comiteado a git** — mismo caso que la sección de demos más arriba,
se hizo en la misma sesión de VPS por SSH.

### Log de auditoría

Tabla `admin_actions` (`user_id` nullable, `action`, `description`, timestamps),
modelo `AdminAction` con un helper estático `AdminAction::record($action,
$description)`. Se llama desde cada acción destructiva/operativa del admin:

- `Admin\MatchController@destroy`
- `Admin\DemoController@destroy`
- `Admin\PlayerController@clearIp`
- `Admin\ConsoleController@kick/message/changeMap/command/restart`

Página nueva: `/adm_cod2/auditoria` (`AuditController@index`), tabla simple con
fecha/admin/acción/detalle, paginada. Link "Auditoría" en el nav admin.

### Control real del servicio desde el panel

Antes solo se podía reiniciar/parar/iniciar `cod2server.service` por SSH. Se
agregaron tres botones en `/adm_cod2/console/{server}` (todos con confirmación —
cortan a todos los jugadores conectados): **Reiniciar** y **Detener** se muestran
cuando el status RCON da OK (server arriba); **Iniciar** se muestra cuando no
(server abajo). Los tres pegan al mismo endpoint `ConsoleController@service`
(`POST admin.console.service`, con `action=restart|stop|start` en el body).

**Esto le da a `www-data` un permiso de sistema nuevo que no tenía (confirmado
2026-08-19: `sudo -l -U www-data` daba "not allowed to run sudo").** Se agregó una
regla en `/etc/sudoers.d/cod2-panel`, validada con `visudo -c` antes de instalarla:

```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart cod2server.service
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop cod2server.service
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start cod2server.service
```

Acotada a propósito a ESAS tres combinaciones exactas, nada de wildcards ni otras
acciones de systemctl (`enable`, `disable`, comandos sobre otros servicios, etc).
`ConsoleController@service` valida `action` contra la misma whitelist
(`in:restart,stop,start`) antes de pasarla a `Process`, asi que ni siquiera con un
request armado a mano se puede colar algo fuera de esas tres. **Este archivo vive
en `/etc/sudoers.d/` del VPS — no es parte de ningún repo, no viaja con git ni con
`deploy.sh`.** Si el VPS se reconstruye desde cero algún día, hay que volver a
crear esta regla a mano (está el comando exacto arriba) para que los botones
vuelvan a funcionar.

`ConsoleController@restart` valida `servers.systemd_service` contra un regex
(`^[a-zA-Z0-9_.-]+\.service$`) antes de pasarlo a `Process` — el nombre sale de la
base de datos, no del request, pero la validación queda ahí igual como defensa en
profundidad. Se agregó la columna `servers.systemd_service` (nullable), el server
existente (`pug-latam`) se backfillió con `cod2server.service`.

**Antes de esto, se decidió explícitamente NO agregar un CoD2x custom para
auto-subir screenshots (2026-08-19).** El dueño lo evaluó y prefirió no seguir
después de que se investigó el costo real: requeriría compilar un `mss32.dll`
propio y que cada jugador lo instale a mano (no hay forma de "empujarlo" desde el
server), y quedaría frágil ante el auto-updater agresivo de CoD2x oficial (ver
`src/mss32/updater.cpp` del repo de CoD2x — el server mismo se auto-reemplaza sin
avisar si `sv_update` queda en `true`, que es el default; no se tocó ese cvar
tampoco, decisión de no modificar nada del server en producción por esto). Si se
retoma esta idea en el futuro, arrancar releyendo esa investigación antes de
proponer nada.

## Bans persistentes (2026-08-19)

Antes solo había Kick (por sesión, se reconecta al toque). Se agregó Ban en
`/adm_cod2/console/{server}`, junto a Kick, para jugadores conectados —
igual de simple para el admin, pero persistente.

**Hallazgo clave: no hace falta tocar el mod ni CoD2x para esto.** `banClient
<slot>` / `banUser <nombre>` / `tempBanClient` / `tempBanUser` / `unbanUser
<nombre>` son comandos **nativos del engine base de CoD2** (confirmado leyendo
el `.c` decompilado de `CoD2MP_s.c` en el repo de CoD2x — no son parte de
CoD2x, existen desde antes). `banClient` escribe el guid en un `ban.txt` en el
gameserver, y el motor mismo rechaza esa conexión en el futuro
(`SV_IsBannedGuid`, se ve en `SV_DirectConnect`) — mismo guid que usa todo lo
demás en este sitio. Como son comandos de consola normales, se mandan por el
mismo `Cod2RconClient` que ya existía para kick/say/map, sin agregar nada nuevo
de infraestructura.

Tabla `bans` (Laravel) es solo el registro/historial — `ban.txt` no guarda
motivo, quién baneó, ni fecha. `unbanUser` busca por **nombre exacto**, no por
guid (confirmado en el código decompilado: "unbanned %i user(s) named %s") —
por eso `bans.player_name` guarda el nombre tal como estaba en el momento del
ban, para que el desbaneo desde `/adm_cod2/bans` siga funcionando aunque el
jugador haya cambiado de nombre después.

**Limitación conocida (v1):** solo se puede banear a alguien que esté
conectado en ese momento (necesita el slot). Banear a alguien offline
(revisando un demo días después, por ejemplo) no está soportado — quedaría
para más adelante si hace falta, probablemente escribiendo a `ban.txt`
directo o agregando un chequeo server-side vía GSC+HTTP (mismo patrón que
demos), no se investigó el formato exacto de `ban.txt` para escritura directa.

Probado en vivo (2026-08-19): `banClient` con slot inválido devuelve "Bad
client slot" (confirma que el comando existe y se ejecuta), y el ciclo
crear/desbanear se probó con un registro sintético en la tabla `bans` (sin
afectar a ningún jugador real).

## Servidores temporales self-service (2026-08-22)

Cualquier visitante (sin login) puede crear su propio servidor de CoD2 temporal desde
`/servidores/crear` — hostname, cantidad de jugadores, mapa, contraseña de acceso
(**obligatoria desde 2026-08-22**, a pedido del dueño — ya no se permite un server
público/sin clave, ver `StoreHostedServerRequest::rules()`), cracked sí/no. Corre en
el MISMO VPS de producción, comparte el binario/mod
compartido con el server real de Pug Latam pero en su propio proceso systemd aislado.
Se auto-apaga solo (por tiempo o por estar vacío) — **es la primera feature pública sin
login que hace fork de un proceso de sistema real en producción**, así que el diseño
entero prioriza que un visitante anónimo nunca pueda tumbar ni degradar el server real
ni el resto del sitio. Config completa en `config/hosted_servers.php` (topes,
duración, rango de puertos — todo vía `.env`, nada hardcodeado).

### Modelo y ciclo de vida

Tabla `hosted_servers`, completamente separada de `servers` — `cod2:parse-log` no la
conoce, así que estas instancias quedan automáticamente fuera del pipeline de
stats/ranking, sin código extra de por medio. `app/Models/HostedServer.php`.

Estados: `starting` → `running` → `stopped`/`expired`/`failed`. `management_token`
(string random de 40) es la ÚNICA "credencial" del creador — no hay cuentas, la URL de
`/servidores/{id}/{token}` es lo único que permite ver/detener ese server
(`HostedServerController::authorizeToken()`, `hash_equals`, 404 si no matchea).

### Por qué el orden de provisioning importa (`HostedServerProvisioner`)

1. Se crea y commitea la fila en BD (`status=starting`, puerto ya reservado) **antes**
   de tocar el sistema operativo — así nunca puede quedar un proceso real corriendo
   sin una fila que lo controle, aunque el request PHP se corte a mitad de camino.
2. Se escribe el directorio/config de la instancia.
3. `sudo systemctl start cod2-temp@{id}.service`.
4. Se hace poll de `Cod2RconClient::status()` hasta ~15s antes de marcar `running` —
   `systemctl start` sobre un `Type=simple` vuelve apenas systemd hace fork+exec, NO
   cuando el gameserver terminó de inicializar (mismo tipo de gotcha ya documentado
   en `ConsoleController` sobre RCON/`sv_floodProtect`). Si nunca responde, se hace
   `stop` best-effort, se limpia el directorio, se libera el puerto, y la fila queda
   `failed`. Esto significa que el POST a `/servidores/crear` puede tardar hasta ~15s
   en responder — es a propósito (mejor que mostrarle un connect string a alguien
   antes de confirmar que el server realmente está arriba), pero hay que tenerlo en
   cuenta si algún día se agrega un timeout más corto en el proxy/PHP-FPM delante de
   esto.

`hosted-servers:expire` (cron cada minuto) limpia tres casos: vencidas por
`expires_at`, vacías hace más de `idle_minutes` (el reloj arranca cuando el server se
confirma arriba, no cuando se crea la fila — así un boot lento no le come ventana de
inactividad a nadie), y filas trabadas en `starting` hace más de 2 minutos
(provisioning que murió a mitad de camino — deploy, worker reciclado, etc.).
`hosted-servers:poll` (cron cada minuto) actualiza `player_count`/
`last_seen_players_at` por RCON.

### Asignación de puerto y tope de concurrencia — sin carreras

`HostedServerPortAllocator` NO usa un `SELECT` + `lockForUpdate()` (no sirve de nada
lockear filas que todavía no existen si el rango de puertos está mayormente libre) —
prueba insertar con cada puerto candidato del rango y confía en la unique key real de
`hosted_servers.port` (nullable — MySQL/MariaDB permiten múltiples `NULL`, así que
instancias expiradas no bloquean nada) como el verdadero guardia atómico: si otro
request se quedó con ese puerto un instante antes, el INSERT tira duplicate-key
(código 1062) y se reintenta con el siguiente. El tope global de concurrencia (evitar
que dos creaciones simultáneas se cuelen las dos aunque solo quede 1 lugar) usa
`Cache::lock()` — un mutex real entre requests — en vez de una simple comparación en
PHP, en `HostedServerController::store()`.

### Inyección de config — el hallazgo más importante de este módulo

`hostname`/`join_password` terminan escritos crudos dentro de un `server.cfg` que el
motor `+exec`uta línea por línea — sin sanear, un valor como `foo"; set rcon_password
"hijacked` cierra la comilla del `set` y le agrega comandos propios al cfg de esa
instancia. `HostedServerSanitizer::cfgValue()` usa un **allowlist** (letras/números/
espacios/puntuación básica/códigos de color `^0`-`^9`), no un blocklist — más fácil de
auditar. `map` no tiene este riesgo porque se valida contra un enum
(`MapCatalog::all()` + `MapCatalog::variantCodes()`, mismo listado que ya usa el
selector de mapas del admin), nunca un string libre.

### `scr_recording` forzado a `"0"` — el segundo hallazgo importante

El mod (cargado desde la base compartida de producción) tiene la URL de subida de
demos **hardcodeada** a `https://cod2.4livepro.com/api/demos/upload/...` (ver sección
"Subida automática de demos por HWID" más abajo) — sin forzar `scr_recording "0"` en
el `server.cfg` generado, cualquier partida SD jugada en un server de prueba
terminaría subiendo demos reales al catálogo `/demos` de producción, sin partida
asociada. `HostedServerConfigWriter` lo fuerza explícitamente, no lo deja en manos del
default del mod.

### `HostedServerConfigWriter` — de dónde sale el ruleset

El `server.cfg` generado **lee el `server.cfg` REAL de producción** en
`config('hosted_servers.game_base_dir')` (el ruleset completo de zPAM: `scr_sd_*`,
límites de armas, `scr_readyup`, MOTD, `sv_wwwBaseURL`, etc.) y le pisa abajo, en
orden, los cvars propios de la instancia (`sv_hostname`, `g_password`,
`rcon_password`, `sv_maxclients`, `sv_cracked`, `scr_recording`) — CoD2 ejecuta un cfg
línea por línea, así que un `set` posterior gana. Se lee del archivo real en vez de
duplicar ~250 líneas a mano para que un ajuste de reglas en producción se refleje acá
solo. `sv_wwwBaseURL`/`scr_motd` se conservan tal cual (no son datos de identidad, son
la URL de descarga del mismo mod compartido).

`net_port` y el mapa inicial (`+map`) son cvars que tienen que estar disponibles ANTES
de que el server termine de inicializar — no alcanza con un `set` dentro del cfg
(mismo motivo por el que `start_libcod.sh` de producción ya los pasa por línea de
comandos). Ninguno de los dos es secreto, así que viajan en un sidecar plano
`instance.env` (`PORT=`/`MAP=`) que `start_libcod_temp.sh` lee con `source`. Todo lo
que SÍ es sensible (`rcon_password`, `g_password`) va dentro del `.cfg`, nunca por
argv — un argumento de línea de comandos queda visible entero para cualquier otro
usuario del VPS vía `ps aux` mientras el proceso corre (mismo motivo por el que
`RunDatabaseBackup` ya usa un `--defaults-extra-file` en vez de pasar la password de
mysql por CLI).

### Filesystem: base compartida de solo lectura + directorio propio por instancia

`fs_basepath` (assets, solo lectura) apunta a la base real de producción
(`/home/gameserver/1.3/puG`) — nunca se duplica. `fs_homepath` (escritura:
config/logs/demos) apunta al directorio propio y chico de cada instancia
(`/home/gameserver/1.3/temp/{id}/`). **Esto todavía no se probó a mano contra el
binario/mod real** — antes de confiar en la automatización conviene lanzar
`cod2_lnxded` una vez manualmente en el VPS con `fs_basepath`/`fs_homepath` separados
para confirmar que el server.cfg se lee desde `fs_homepath` como se espera.

### Systemd + sudoers + permisos — SOLO en el VPS, no versionado en este repo

**Activado en producción el 2026-08-22.** Mismo precedente que
`/etc/sudoers.d/cod2-panel` (ver "Auditoría de admin y reinicio de servicio" más
abajo): un unit systemd **template** `cod2-temp@.service` (`%i` =
`hosted_servers.id`) instalado en `/etc/systemd/system/`, y dos líneas nuevas de
sudoers:

```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start cod2-temp@*.service
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop cod2-temp@*.service
```

Copias de referencia versionadas en el repo `ZPAM COD2` (mismo patrón que
`cod2server.service`/`start_libcod.sh`): `cod2-temp@.service`,
`start_libcod_temp.sh` (este último vive además, copiado a mano, en
`/home/gameserver/1.3/puG/start_libcod_temp.sh` en el VPS — mismo lugar que
`start_libcod.sh` real, no en un checkout de git separado). `CPUWeight=100` (muy por
debajo del `9500` del server real, a propósito, para que jamás le compita por CPU),
sin `Nice` negativo, `MemoryMax=150M`, `Restart=on-failure` (no `always`), y
`OOMScoreAdjust=500` — todo lo contrario al server real (`-900`, protegido): una
instancia temporal debe ser la PRIMERA candidata del OOM killer si el sistema se
queda sin RAM, nunca el server real ni el panel.

**Permiso de escritura de `www-data` sobre `/home/gameserver/1.3/temp/` — encontrado
en vivo, no estaba en el plan original.** Primera prueba manual (vía `tinker` por SSH
como `root`) funcionó de punta a punta, pero el primer intento real desde la web
(corriendo como `www-data`, el usuario real de Apache/PHP) falló con `mkdir():
Permission denied` en `HostedServerConfigWriter.php:34` — todo `/home/gameserver/1.3/`
es de `root` con `755`, así que `www-data` podía leer/atravesar pero no crear nada
ahí. Fix (ya aplicado): `chown www-data:www-data /home/gameserver/1.3/temp` — SOLO ese
directorio, no `puG/` (la base de assets/mod/binario compartida sigue siendo de
`root`, de solo lectura para `www-data`, a propósito: si el panel se viera
comprometido algún día, no debería poder tocar el binario/mod que usa el server
real). **Lección para el próximo módulo que escriba archivos fuera de
`storage/`**: probar el flujo real como `www-data` (`sudo -u www-data ...` o
directo desde el navegador), no solo por SSH como `root` — root puede escribir en
cualquier lado y esconde este tipo de bug de permisos hasta que ya está en
producción.

**El directorio de la instancia se borra bien al detener un server temporal, pero el
journal de systemd NO — encontrado en vivo 2026-08-23, durante una crisis de disco
real (98% usado, 354MB libres).** `HostedServerProvisioner::teardown()` borra el
directorio completo de la instancia (`server.cfg`, `instance.env`, `games_mp.log`),
pero la salida de consola del proceso (`cod2-temp@{id}.service`) queda capturada por
`systemd-journald` en `/var/log/journal/`, completamente separada del directorio de
la instancia — sin ningún límite configurado, journald usaba el default de systemd
(~10% del filesystem), y con 14 instancias creadas desde el lanzamiento ya se habían
acumulado 91.2MB solo de esos logs. Fix aplicado en `/etc/systemd/journald.conf`
(archivo de sistema, **no versionado en este repo** — si el VPS se reconstruye hay
que volver a agregar esto a mano):

```
[Journal]
SystemMaxUse=300M
MaxRetentionSec=14day
```

(`cp` a `/etc/systemd/journald.conf.bak-20260823` antes de tocarlo, por las dudas.)
Aplica a **todo** el journal del sistema, no solo a `cod2-temp@*` — journald no separa
límites por unit sin usar namespaces de journal (`LogNamespace=`, no configurado, más
complejidad de la que hacía falta acá). 300M es generoso dado el ritmo real (~6.5MB
por sesión de server temporal) pero sigue estando muy por debajo de lo que el disco de
14GB puede tolerar. Requiere `sudo systemctl restart systemd-journald` para aplicarse
(probado en vivo: Apache/MySQL/el gameserver real siguieron arriba sin interrupción
durante el reinicio — journald no los toca). Si en el futuro se agregan más servicios
ruidosos en journal, reconsiderar `SystemMaxUse` hacia arriba junto con la RAM/disco
del VPS en general, no solo por este módulo.

**`max_concurrent=2`, puertos 28970-28971 — subido de 1 a 2 a pedido del dueño
(2026-08-22), sabiendo que es ajustado.** El VPS tiene mucho menos margen del que se
asumió al diseñar esto: 913MB de RAM totales, y una prueba real en vivo con UNA sola
instancia corriendo (119M de uso real de 150M de tope) dejó solo 176MB "disponibles"
(de 286MB que había libres antes de esa prueba) — el server real de Pug Latam usa
128.8M él solo, más MySQL (~83M), Apache (~280M en varios workers) y el queue worker
(~64M) ya se comen el resto, y el swap ya tenía uso antes de sumar nada nuevo. El
disco también está al 93% (1.1GB libres de 14GB) — y ese margen resultó ser incluso
más frágil de lo pensado: al día siguiente (2026-08-23) llegó a 98%/354MB libres
(retención de demos + journal de systemd sin límite, ver más abajo y la sección de
Demos), una crisis real de espacio. Con 2 concurrentes el margen de RAM libre puede
bajar a ~60MB en el peor caso — el `OOMScoreAdjust=500` de arriba es lo que hace que
esto sea tolerable (una instancia temporal se sacrifica antes que el server real o el
panel), pero si en la práctica da problemas, bajar `max_concurrent` de nuevo a 1 antes
que subirlo más. Subir esto más allá de 2 solo después de ampliar RAM/disco del VPS,
nunca por confianza en la teoría.

**El límite editable del panel y el rango de puertos eran dos números independientes
que nadie sincronizaba — arreglado 2026-08-25.** El módulo de admin que permite subir
`max_concurrent` desde `/adm_cod2/servers` (`Setting::hosted_servers_max_concurrent`)
se agregó el 2026-08-24 a propósito sin capar contra la cantidad real de puertos
abiertos — documentado en su momento como una trampa conocida ("si se sube por
encima de los puertos realmente disponibles, la creación de un servidor temporal
falla con un error genérico"). El dueño lo notó en la práctica: subir el límite en el
panel no alcanzaba porque el rango de puertos (`HOSTED_SERVERS_PORT_START`/`_END` en
`.env`, fijo en 28970-28971) seguía teniendo solo 2 puertos.

Fix: `settings.hosted_servers_max_concurrent` (un número) se reemplazó por
`settings.hosted_servers_ports` (texto, lista de puertos separada por coma, ej.
`"28970,28980,28990"`) — `Setting::maxConcurrent()` ahora es simplemente
`count(Setting::hostedServerPorts())`, así que los dos números no pueden
desincronizarse nunca más (es matemáticamente imposible, no una convención a
respetar). `HostedServerPortAllocator` itera esa lista en vez de un rango
`start`/`end`. El panel (`/adm_cod2/servers`) tiene un solo campo de texto editable
con la lista completa, en vez del número + el texto informativo de antes.

**Regla de negocio nueva, a pedido del dueño:** si se edita la lista sacando un
puerto que tiene un servidor temporal activo (`starting`/`running`) ahora mismo, el
guardado se rechaza entero (con el puerto en conflicto en el mensaje de error) — no
se permite guardar y dejar ese servidor corriendo "fuera de lista". El resto de la
validación es básica a propósito (número de puerto válido 1024-65535, sin duplicados,
al menos uno) — no revisa contra otros servicios del VPS, confía en que el admin sabe
qué puertos están libres (mismo criterio que ya se usó para no capar contra el
firewall, ver arriba).

De paso se encontró y corrigió un bug latente en `HostedServerPortAllocator`:
`isDuplicateKey()` solo reconocía el código de error 1062 de MySQL para detectar un
puerto ya tomado — bajo SQLite (lo que usan los tests) esa excepción nunca se
atrapaba, algo que nunca se notó porque ningún test anterior ejercitaba ese camino y
producción siempre corrió contra MySQL. Reemplazado por
`Illuminate\Database\UniqueConstraintViolationException` (agnóstica de motor).

**Importante — esto NO cambia el techo real de cuántos servidores temporales
concurrentes el VPS aguanta.** El límite real sigue siendo la RAM (ver el párrafo de
arriba: con 2 concurrentes el margen libre ya baja a ~60MB en el peor caso). Ahora es
posible configurar más puertos sin que la creación falle con un error genérico, pero
subir la lista más allá de 2 sigue siendo una decisión de recursos, no solo de
puertos — misma advertencia que ya existía, ahora aplicada correctamente porque el
número que se sube en el panel por fin es el que realmente importa.

**UI del panel rediseñada el mismo día (2026-08-25), a pedido del dueño: un campo de
puerto por servidor en vez de una lista en texto.** El campo único con
`"28970,28980,28990"` se reemplazó por: un número "¿Cuántos servidores temporales
querés permitir?" que controla (JS vanilla, `cod2SyncPortSlots()`, sin frameworks —
mismo patrón que ya usan los dropdowns del nav) cuántas filas de puerto se muestran,
más una fila por servidor con su propio input ("Servidor temporal #1", "#2", ...). El
número de cantidad es **solo control de UI, no se guarda aparte** — lo que se manda al
guardar sigue siendo la lista de puertos (ahora como array, `hosted_servers_ports[]`,
uno por fila), así que el límite de concurrencia se sigue derivando exclusivamente de
cuántos puertos hay, sin reabrir el bug original de dos números desincronizados. Subir
la cantidad agrega una fila nueva con un puerto sugerido (el último +10, editable);
bajarla saca la última fila — la regla de negocio de no poder sacar un puerto con
servidor activo (ver arriba) sigue aplicando igual, ahora contra el array completo en
vez de un string partido por comas.

**Bug real, se lo topó el dueño jugando con el panel nuevo (2026-08-25): un puerto de
servidor temporal chocaba con Pug Latam.** Le asignó a "Servidor temporal #1" el
puerto `28960` — exactamente el mismo que usa el server real (`servers.connect_port`).
La validación nunca lo revisaba: durante el diseño original se preguntó explícitamente
si validar contra esto, y se eligió a propósito la opción "básica" (sin chequear
contra otros servicios del VPS) — la colisión real terminó confirmando que hacía
falta igual. Fix en `SettingController::updateHostedServers()`: rechaza el guardado si
algún puerto de la lista coincide con `connect_port`/`rcon_port` de un server real con
`is_active=true` (uno desactivado ya no compite por el puerto). El dato ya guardado en
producción (`28960,28971`) se corrigió a mano a `28970,28971` (los mismos puertos que
tenía antes de este cambio) — verificado que no había ningún servidor temporal activo
en el puerto 28960 antes de tocarlo, así que no hizo falta parar nada.

**Probado en vivo de punta a punta 2026-08-22** (crear vía `tinker`, confirmar
proceso/RCON/`fs_basepath`+`fs_homepath` separados, `stop()`, limpieza completa,
memoria recuperada, log de producción sin tocar, server real sin degradación) — ver
historial de conversación si hace falta el detalle exacto de esa prueba.

### Gametype fijo en SD, con su limitación conocida

Las instancias temporales arrancan en Search & Destroy, igual que Pug Latam — decisión
explícita del dueño (2026-08-22) sabiendo que zPAM usa "ready up" (ambos equipos deben
confirmar listos antes de que arranque la ronda): una sola persona probando su server
no va a ver acción hasta que se sume alguien más. El aviso explícito de esto se sacó
de `hosted-servers/create.blade.php` (a pedido del dueño, mismo día) — sigue estando
en `show.blade.php` (la página del server ya creado, una vez que está corriendo).

### UI del formulario de creación (2026-08-22)

Referencia de diseño: `fshost.me/free/cod2` (hosting gratuito de terceros, capturas
mostradas por el dueño) — de ahí se tomó la idea del banner ancho con el logo del
juego y la fila de "ubicación" con bandera + disponibilidad, pero **no se copió la
imagen de fondo de ese sitio** (arte compuesto/box art de Activision, de un
competidor) — el banner de `hosted-servers/create.blade.php` usa
`public/logo_cod2.webp` (ya estaba en el repo) sobre un degradado CSS propio, no una
imagen descargada de otro lado.

- **Ubicación**: la fila "Miami, FL, Estados Unidos" no es decorativa al azar —
  confirmado por geoip (`ipinfo.io/167.148.33.82`) que el VPS real está en Miami. Sin
  selector (a diferencia de la referencia, que tiene varios datacenters) porque acá
  hay un solo VPS — mostrar un dropdown que no lleva a ningún lado sería un falso
  affordance. El badge de disponibilidad (`$available`, calculado en
  `HostedServerController::create()` como `max_concurrent - activos`) es real, no
  hardcodeado.
- **Nombre del servidor con sufijo fijo**: el usuario tipea hasta
  `HostedServer::NAME_MAX_LENGTH` (20) caracteres, y `HostedServer::NAME_SUFFIX`
  (`" @ Pug Latam"`) se pega SIEMPRE al final en `HostedServerController::store()`
  antes de guardar — no es editable, todo servidor temporal queda identificado como
  parte de la comunidad. El campo del form muestra el sufijo como texto fijo al lado
  del input (no editable) para que quede claro antes de enviar.
- **Selector de mapa con miniatura**: se reemplazó el `<select>` de texto por una
  grilla de botones con imagen, mismo patrón que ya usa el selector de mapas del
  admin (`admin/console.blade.php`, `MapImage::url($code)`) — mapas sin imagen
  subida muestran un ícono genérico en vez de romper el layout.
- **Medidor de latencia real** (2026-08-23), al final del badge de ubicación en el
  hero (después de "N disponibles"/"N creados ahora"). **También en la home**
  (`dashboard.blade.php`, al final del badge "N jugadores conectados") — misma
  función compartida, `window.cod2MeasurePing(elId)` en `layouts/app.blade.php`
  (antes vivía duplicada inline en `create.blade.php`, se subió al layout el mismo
  día que se agregó a la home, mismo patrón que `cod2CopyConnect`/
  `openDetailsPopover`). Cada página solo pone el `<span id="...">` y llama
  `cod2MeasurePing('ese-id')`. 3 fetch con `cache: 'no-store'`, descarta la primera
  muestra (siempre sale más alta por el warmup de la conexión TLS) y promedia las
  últimas 2 — verde `<80ms`, amarillo `<150ms`, rojo el resto.

  **No le pega al dominio público — pega directo a `direct.cod2.4livepro.com`.**
  Primer intento (medía contra `cod2.4livepro.com`, el dominio de siempre) dio
  ~100ms en el navegador del dueño mientras el ping real in-game daba ~65ms — el
  sitio está detrás de Cloudflare (proxy naranja), así que esa medición incluía el
  salto extra por el borde de Cloudflare + handshake TLS, no la ruta real al VPS.
  Fix (mismo día): se creó `direct.cod2.4livepro.com` como registro DNS **DNS-only**
  en Cloudflare (nube gris, sin proxy — el dueño lo creó él mismo) apuntando directo
  a la IP del VPS. Del lado del VPS (todo manual, **nada de esto es parte del repo**):
  - Certificado expandido con `certbot certonly --apache --cert-name
    cod2.4livepro.com -d cod2.4livepro.com -d direct.cod2.4livepro.com --expand`
    (un solo cert cubre los dos hostnames, se renueva junto).
  - `ServerAlias direct.cod2.4livepro.com` agregado a los dos vhosts existentes de
    `cod2.4livepro.com` (`:443` y `:80`, en `/etc/apache2/sites-available/
    cod2.4livepro.com*.conf` — mismo `DocumentRoot`, mismo Laravel, no hace falta un
    vhost separado). Backups de los `.conf` originales con sufijo
    `.bak-20260823` en el mismo directorio.
  - El `RewriteCond %{SERVER_NAME} =cod2.4livepro.com` del vhost `:80` (redirect a
    HTTPS) se amplió con `[OR]` + una segunda condición para el alias — sin esto,
    una visita a `http://direct...` (sin `s`) no hubiera redirigido.
  - `mod_headers` habilitado (`a2enmod headers` + restart — no estaba activo antes
    de esto) para que los `Header` de `.htaccess` funcionen.

  **`/ping` dejó de ser una ruta de Laravel — es `public/ping`, un archivo estático
  vacío** (mismo día, segunda vuelta de optimización). El dueño comparó el medidor
  contra un `ping` de consola (ICMP directo, ~60ms) y seguía viendo ~20ms de más
  incluso ya pegándole al subdominio directo — la diferencia real que queda es que
  un `fetch()` de navegador nunca puede mandar ICMP crudo (limitación del navegador,
  no de este sitio), así que cada muestra sigue siendo un request HTTPS de verdad.
  Lo único evitable era el bootstrap de Laravel (rutas, middleware, kernel HTTP) en
  cada una de las 3 muestras — se sacó `Route::get('/ping', ...)` de
  `routes/web.php` y se creó `public/ping` (archivo vacío, versionado en el repo)
  en su lugar: el `RewriteCond %{REQUEST_FILENAME} !-f` que ya trae el
  `.htaccess` de Laravel de fábrica hace que Apache lo sirva directo, sin tocar
  `index.php`/PHP-FPM para nada. El header CORS + `Cache-Control: no-store` ahora
  viven en `public/.htaccess` (`<Files "ping"><IfModule mod_headers.c>...`), no en
  código PHP — esto sí viaja con el repo normal, a diferencia del resto de esta
  sección.

  Si el VPS se reconstruye desde cero, hay que recrear el registro DNS, expandir/
  reemitir el certificado, volver a agregar el `ServerAlias` a mano, y correr
  `a2enmod headers` — nada de eso sobrevive a un `deploy.sh` ni está versionado
  (`public/ping` y `public/.htaccess` sí, esos dos sobreviven solos).
- **`MapCatalog::pickerOptions()`** (nuevo): un código por mapa real para selectores
  públicos — usa la variante `_fix`/`_bal` instalada si existe (`toujane_fix`,
  `dawnville_fix`, `burgundy_fix`, etc.), el código base si no. A diferencia del
  picker del admin (que muestra base + todas las variantes a propósito), esto evita
  duplicados confusos para un visitante público. Mapas no-stock que nunca se
  recorren desde `MAPS` (`mp_chelm_fix`, `mp_crossroads`, `mp_vallente_fix`,
  `wawa_3daim`) quedan afuera solos. Orden con Toujane/Dawnville/Burgundy/Stalingrad
  primero, a pedido explícito del dueño. `HostedServerController` y
  `StoreHostedServerRequest` usan este método en vez de construir la lista a mano.

### Iteración rápida post-lanzamiento (2026-08-22)

Varios ajustes chicos el mismo día del lanzamiento, mientras el dueño probaba el
flujo en vivo — vale la pena tenerlos en cuenta si se vuelve a tocar esta parte:

- **Ancho del contenedor subió tres veces seguidas** (`max-w-xl` → `max-w-2xl` →
  `max-w-3xl` → `max-w-4xl`) a medida que se agregaban más columnas de mapas y se
  emparejaban Jugadores+Contraseña — cada salto se sintió apretado apenas se probó
  con contenido real. **Hoy (commit `6a6f809`) ambas páginas sacaron el `max-w-4xl`
  propio directamente**, para heredar el `max-w-6xl` del `<main>` del layout — mismo
  ancho que la home, en vez de seguir subiendo el número a mano.
- **Grilla de mapas sin scroll interno**: tenía `max-h-80 overflow-y-auto` para que
  no empujara el resto del form — a pedido del dueño se sacó para mostrar todos los
  mapas de una, aceptando que el form sea más largo.
- **Campo de contraseña duplicado (bug real, ya corregido)**: al emparejar
  Jugadores+Contraseña arriba en el rediseño del hero, quedó sin sacar el campo
  viejo de más abajo — dos `<input name="join_password">` en el mismo form, el
  navegador solo mandaba el segundo e ignoraba lo que el usuario tipeaba en el que
  realmente se veía. Si se ve que la contraseña ingresada no coincide con la que
  terminó pidiendo el server, revisar que no haya vuelto a aparecer un input
  duplicado.
- **Sufijo `" @ Pug Latam"` llegaba sin el `@` al juego (bug real, ya corregido)**:
  `HostedServerSanitizer::cfgValue()` (el allowlist de caracteres para todo lo que se
  escribe en `server.cfg`) no incluía `@` — se comía el sufijo fijo antes de
  escribirlo, así que el scoreboard in-game mostraba `"Nombre  Pug Latam"` sin el
  arroba. Confirmado con una captura real del scoreboard. Cualquier otro carácter
  nuevo que se necesite agregar a un valor que termine en `server.cfg` tiene que
  pasar por este mismo allowlist, no asumir que "es solo un símbolo" es inofensivo
  para el parser de cfg — ver también la nota de inyección de config más arriba.
- **`idle_minutes` bajado de 15 a 5** (`config/hosted_servers.php`), a pedido del
  dueño — igual mecanismo de siempre (`hosted-servers:expire`, cron cada minuto), no
  se tocó la lógica, solo el número.
- **Comando RCON separado del texto de ayuda**: antes el `rcon login <password>` iba
  mezclado dentro de un párrafo de instrucciones; ahora tiene su propio bloque
  `<code>` + botón "Copiar" (`cod2CopyConnect()`, mismo helper que ya usa el connect
  string), igual patrón visual en `create.blade.php` y `show.blade.php`.
- **Hero con imagen del mapa también en la página del server** (`show.blade.php`),
  no solo en la de crear — usa la imagen real del mapa elegido (`MapImage::url`) en
  vez del carrusel genérico de `create.blade.php` (ahí no rota, es un solo mapa fijo,
  más relevante que un carrusel).
- **Contador de "N creados ahora"** en el badge de ubicación del hero de
  `create.blade.php` (junto a "N disponibles") — nuevo campo `active` que
  `HostedServerController::create()` ya calculaba mentalmente pero no pasaba a la
  vista.
- **Ancho y tamaño del hero igualados a la home, en más de una vuelta.** Primero se
  sacó el `max-w-4xl` propio de `create.blade.php`/`show.blade.php` para heredar el
  `max-w-6xl` del layout. Después se notó que el hero se veía más bajo que el de la
  home aun con el mismo padding (`py-10 sm:py-16 md:py-24`) — la causa real es que el
  hero de la home tiene una fila extra de dos botones CTA ("Estado del servidor" /
  Discord, ver `dashboard.blade.php`) que `create.blade.php` no necesita (el form ya
  es el CTA), así que con contenido más corto quedaba más bajo pese a compartir el
  mismo padding. Se subió el padding de `create.blade.php` a `py-12 sm:py-20
  md:py-32` para compensar la altura del bloque de botones que falta, en vez de
  agregar botones falsos solo para igualar el alto.
- **Grilla de mapas con miniaturas más grandes**: bajada de `lg:grid-cols-6` a
  `lg:grid-cols-5` (menos columnas, cada tarjeta más ancha), `gap-2.5` → `gap-3`, el
  ícono de fallback (mapas sin imagen subida) de `w-6 h-6` a `w-9 h-9`, y el label de
  `text-xs` a `text-sm`.

### Mitigación de abuso

Tope global de concurrencia (ver arriba) + un campo honeypot (`website`, oculto con
`sr-only`, regla `prohibited`) en el form, más **Cloudflare Turnstile** (agregado
2026-08-22, a pedido del dueño — el sitio ya está detrás de Cloudflare, así que no
suma un proveedor nuevo). `HostedServerController::passesTurnstile()` verifica el
token contra `https://challenges.cloudflare.com/turnstile/v0/siteverify` antes de
tomar el lock de concurrencia (no tiene sentido tener a otros requests esperando el
lock mientras se espera esa respuesta externa). **Las keys (`TURNSTILE_SITE_KEY`/
`TURNSTILE_SECRET_KEY` en `.env`, ver `config/services.php`) todavía no están
cargadas en el VPS** — hay que generarlas a mano desde el dashboard de Cloudflare
(Turnstile → Add site), no se pueden generar desde este repo. Mientras no estén
configuradas, el widget no se renderiza y la verificación se salta sola (no rompe el
form) — el honeypot/lock siguen siendo la base de todos modos.

**El throttle en la ruta de creación fue reactivado el 2026-08-24 como `throttle:20,1,hosted-create`**
(20 requests por minuto, con prefijo de clave para no compartir bucket con futuras rutas).
El original `throttle:3,60` — y el primer intento de reactivación como `throttle:20,60` — cayeron
en la misma trampa: el segundo argumento del middleware `throttle` en Laravel es **decayMinutes**
(minutos), no segundos. Así que `throttle:20,60` significaba 20 intentos por HORA, no por minuto,
60 veces más estricto de lo que el comentario del código, el plan y el nombre del test realmente
decían. Eso es exactamente por qué el `throttle:3,60` original bloqueaba las pruebas del dueño
casi al instante. El honeypot, Turnstile (una vez que tenga keys) y el tope global de
concurrencia siguen siendo las otras capas de protección.

### Bug real: `$request->ip()` siempre devolvía un borde de Cloudflare, no el visitante real (2026-08-25)

El dueño creó 2 servidores temporales él mismo y preguntó cómo se valida "un usuario"
sin cuentas — investigando la respuesta (la única señal real disponible es
`creator_ip`) se encontró que esa columna estaba mal desde el principio: los dos
servidores quedaron con `creator_ip=172.68.125.137`, un rango de **Cloudflare**
(`172.64.0.0/13`), no una IP residencial. Causa: el sitio está detrás de Cloudflare
(proxy) pero `bootstrap/app.php` nunca tuvo `trustProxies()` configurado — sin eso,
Laravel toma la IP de la conexión TCP directa (siempre un borde de Cloudflare) en vez
de leer el header real (`X-Forwarded-For`). Esto también dejaba **sin efecto real**
el throttle por IP que ya existía en la ruta de creación (`throttle:20,1,hosted-create`,
párrafo de arriba) — visitantes distintos que pegan al mismo borde de Cloudflare
comparten el mismo "IP" a ojos de Laravel.

Fix: `$middleware->trustProxies(at: [...])` en `bootstrap/app.php`, con la lista
oficial de rangos IPv4+IPv6 de Cloudflare (`cloudflare.com/ips`, cambia rara vez).
Laravel/Symfony solo confían en `X-Forwarded-For` cuando la conexión TCP real vino de
uno de esos rangos — alguien que le pegue directo al origen (`151.245.32.43` o
`direct.cod2.4livepro.com`, que a propósito no pasa por Cloudflare, ver "Medidor de
latencia real" más abajo) no puede spoofear su IP con un header falso, porque el
framework ignora el header si el peer inmediato no está en la lista. Esto arregla
`$request->ip()` **en todo el sitio**, no solo en este form.

### 1 servidor temporal activo por IP (2026-08-25)

Seguimiento directo del bug anterior — con la IP real ya resuelta, tiene sentido
usarla para el límite que el dueño pidió: `HostedServerController::store()` ahora
rechaza una segunda creación mientras la primera siga activa (`starting`/`running`)
desde la misma IP, **redirigiendo directo al servidor existente** en vez de un error
genérico. Es el primer chequeo de todo el método (antes de Turnstile) — es una query
local barata, no tiene sentido gastar la llamada externa a Cloudflare si de todos
modos vamos a redirigir. Reusa `creator_ip` (ya se capturaba), sin columna ni
migración nueva.

**Limitación conocida y aceptada a propósito:** es por IP, no por persona real — dos
jugadores detrás del mismo router/NAT (casa, oficina, CGNAT de algunos móviles)
comparten IP pública y quedarían bloqueados entre sí aunque sean gente distinta. Sin
cuentas no hay una señal más fuerte disponible; decisión explícita del dueño de
aceptar ese trade-off en vez de no tener límite alguno.

### Reorden y rediseño de la sección de servidores temporales en `/adm_cod2/servers` (2026-08-25)

A pedido del dueño ("no me gusta" el diseño anterior, sin más detalle al principio —
se aclaró que era sobre esta sección del panel admin, no el dashboard público):

- **Orden:** la tabla de servidores reales (Pug Latam) pasa a mostrarse primero; la
  tarjeta "Servidores temporales (self-service)" queda debajo, no arriba.
- **Rediseño:** el stepper numérico "¿Cuántos servidores temporales querés
  permitir?" (que regeneraba filas de puerto por detrás, y solo podía sacar la
  ÚLTIMA fila al bajar el número) se reemplazó por un botón **"+ Agregar servidor
  temporal"** y un botón **"✕" por fila** para sacar esa fila puntual — patrón
  estándar de lista editable, más predecible. El header de la tarjeta ahora tiene
  una barra de progreso "N/M activos" (antes solo texto) y el estado de Turnstile
  como badge chico ahí mismo (antes una franja de texto suelta al pie de la
  tarjeta). JS vanilla (`cod2AddPortSlot()`/`cod2RemovePortSlot()`), sin
  frameworks — mismo patrón que el resto del panel.
- El envío sigue siendo el mismo array `hosted_servers_ports[]` de siempre — el
  backend (`SettingController::updateHostedServers`) no cambió nada.

### Cómo volver a tu servidor sin login (2026-08-22)

No hay cuentas — la URL `/servidores/{id}/{token}` es la única credencial real (ver
"Modelo y ciclo de vida" arriba), así que si el visitante navega a otra página del
sitio la pierde. `HostedServerController::store()` ahora deja una cookie
`HostedServer::COOKIE_NAME` (`hosted_server`, valor `"{id}|{token}"`, Laravel la
encripta como cualquier cookie normal) con la misma duración que el servidor; `stop()`
la borra (`Cookie::forget()`).

Un `View::composer('layouts.app', ...)` en `AppServiceProvider::boot()` resuelve y
valida esa cookie en **cada página pública** (no solo las de `hosted-servers`), sin
tener que tocar cada controller — comparte `$activeHostedServer` con el layout. La
validación reusa el mismo criterio que `authorizeToken()`: `hash_equals()` contra el
`management_token` real (la cookie es solo conveniencia para no perder el link, nunca
reemplaza al token como credencial) y `isActive()` (si el server ya se detuvo/expiró,
no se muestra nada — no vale la pena mandar a nadie a una página muerta). Cuando hay
un server activo, aparece un ícono con un punto verde animado al lado del logo
(`layouts/app.blade.php`) que linkea directo a `hosted-servers.show`.

## Temporadas — infraestructura base (2026-08-25)

Primer sub-proyecto de un plan de 3 para agregar el concepto de "temporada" al
panel. Spec completa:
`docs/superpowers/specs/2026-08-25-temporadas-infraestructura-base-design.md`.
**Este sub-proyecto SOLO agrega la infraestructura de datos + el panel de admin
para cerrar/abrir temporadas — `/ranking` y `/especialidades` todavía NO son
conscientes de temporada** (siguen mostrando el acumulado histórico completo, sin
filtro), eso queda para los sub-proyectos 2 y 3, todavía no arrancados.

### Modelo: `seasons` + `matches.season_id`

Tabla `seasons` (`name`, `started_at`, `ended_at` nullable) y columna nueva
`matches.season_id` (FK, nullable a nivel de esquema — ver más abajo por qué).
`Season::current()` es `whereNull('ended_at')->firstOrFail()` — exactamente una
fila con `ended_at` null en todo momento, garantizado por
`Admin\SeasonController@store` (ver abajo), nunca por una constraint de base de
datos. La migración
(`database/migrations/2026_08_25_180000_create_seasons_table_and_backfill_matches.php`)
crea "Temporada 1" (con `started_at` = la partida más vieja existente, o `now()`
si la base está vacía) y backfillea `season_id` en TODAS las partidas existentes
a esa temporada — no queda ninguna partida histórica sin temporada.

**`season_id` se asigna una sola vez, al crear la partida — nunca se reasigna
retroactivamente.** `ParseCod2Log::openRound()` (donde se crea cada `GameMatch`
nueva) le pone `Season::current()->id` en el momento de la creación, punto. Esto
es intencional, no una limitación: significa que una partida que está en curso
justo cuando el dueño cierra una temporada desde el admin queda **entera** en la
temporada vieja, sin ninguna lógica especial de "cortar" una partida a mitad de
camino — mismo espíritu que ya usa `log_parser_state.current_match_id` para no
tratar una partida en curso como si ya hubiera terminado (ver la bitácora de bugs
más arriba, entrada 15).

**Por qué `season_id` es nullable a nivel de esquema (no `NOT NULL`), decidido
mientras se escribía el plan de implementación:** forzar esa constraint sobre una
columna ya creada requiere `Blueprint::change()`, que a su vez necesita
`doctrine/dbal` — no instalado en este proyecto, y agregarlo solo para esto no se
justificaba. Además `SQLite` (el motor real que corren los tests, ver
`phpunit.xml`) no soporta bien ese tipo de alteración sin reconstruir la tabla
entera. La garantía real de "toda partida tiene `season_id`" la da el código de
aplicación (el único lugar que crea un `GameMatch` es `openRound()`, que siempre
lo setea) — no una constraint de la base de datos. Si en algún momento se agrega
`doctrine/dbal` por otro motivo, valdría la pena revisar si conviene endurecer
esto.

**`rounds` y `kills` NO tienen su propia columna `season_id`, a propósito.**
Ambas ya cuelgan de `matches` (`rounds.match_id`, `kills.match_id` indirecto vía
`round_id`/`match_id`), así que la temporada de cualquier ronda o kill siempre se
puede derivar con un join a `matches.season_id` sin duplicar el dato — agregar la
columna en las dos tablas más grandes de la base (las que más filas acumulan) sin
necesidad real hubiera sido puro costo de espacio/mantenimiento.

### Panel de admin: cerrar/abrir temporadas — 100% manual, global

`/adm_cod2/temporadas` (`Admin\SeasonController`, link nuevo "Temporadas" en el
dropdown "Sistema" del nav admin). Lista todas las temporadas (nombre, desde,
hasta, cantidad de partidas vía `withCount('matches')`) y un form para cerrar la
activa y abrir una nueva con nombre elegido a mano.

**Por qué 100% manual (sin fecha de corte automática, sin duración configurable):**
no hay ninguna regla de negocio real detrás de "cuándo termina una temporada" más
allá de la decisión del dueño de la comunidad — a diferencia de los servidores
temporales (que sí tienen reglas claras de expiración por tiempo/inactividad), acá
automatizar el corte sería inventar una regla que nadie pidió. `store()` valida
solo `name` (`required|string|max:100`) y hace el swap dentro de una transacción
(`DB::transaction`): cierra la vieja (`ended_at = now()`) y crea la nueva
(`ended_at = null`) atómicamente, así que nunca hay una ventana sin ninguna
temporada activa (lo que rompería `Season::current()->firstOrFail()` en medio de
un `cod2:parse-log` corriendo en paralelo). Se registra en el log de auditoría
existente (`AdminAction::record('seasons.close', ...)`, mismo patrón que
`Admin\MatchController@destroy` y el resto de acciones admin — ver "Log de
auditoría" más arriba).

**Por qué global (una sola temporada activa para todo el sitio, no una por
server):** con un solo server real (`servers.id=1`, Pug Latam) en producción hoy,
una temporada por server hubiera sido complejidad sin caso de uso real —
`Season::current()` no filtra por `server_id` en absoluto. Si en el futuro se
agrega un segundo server real con su propio calendario de temporadas, esto habría
que revisarlo (probablemente agregando `server_id` nullable a `seasons` en vez de
rehacer el modelo).

TDD en las 3 tasks: `tests/Feature/SeasonModelTest.php` (3 casos — la migración
siembra "Temporada 1" activa, `current()` devuelve la de `ended_at` null, la
relación `GameMatch::season()`), `tests/Feature/ParseCod2LogSeasonTest.php` (2
casos — una partida nueva toma la temporada activa; tras cerrar una temporada y
abrir otra, la siguiente partida toma la nueva), y
`tests/Feature/Admin/SeasonControllerTest.php` (4 casos — index muestra la
activa, store cierra+abre atómico, store exige `name`, store deja rastro en
`AdminAction`).

**Reactivar una temporada cerrada (2026-08-27), a pedido del dueño para poder
probar el corte de temporada sin quedar sin vuelta atrás.** El diseño original
era intencionalmente de una sola dirección (cerrar + abrir nueva, sin "volver")
— al usarlo por primera vez en la práctica, el dueño notó que no había forma de
volver a una temporada anterior desde el panel. Se agregó
`Admin\SeasonController::reactivate(Season $season)` (`POST
/adm_cod2/temporadas/{season}/reactivar`, `admin.seasons.reactivate`): mismo
patrón atómico que `store()` (`DB::transaction` + `lockForUpdate()`), pero en
vez de crear una fila nueva, cierra la activa actual y reabre la elegida
**sin tocar su `started_at` original** — solo `ended_at` cambia. Rechaza
reactivar la que ya está activa (defensivo, la UI no ofrece ese botón en esa
fila). Sin restricción de "solo la más reciente" — cualquier temporada cerrada
se puede reactivar, incluida la primera. Botón "Reactivar" por fila cerrada en
`/adm_cod2/temporadas`, con confirmación explicando que cierra la activa
actual. TDD: 3 casos nuevos en `SeasonControllerTest.php` (reactivar
cierra+reabre correctamente preservando `started_at`; rechaza reactivar la ya
activa; deja rastro en `AdminAction` como `seasons.reactivate`) — suite
completa 115 tests/436 assertions, 1 fallo preexistente conocido, sin
regresiones.

**Pendiente (fuera de alcance de este sub-proyecto):** `/ranking`,
`/especialidades`, y cualquier otra pantalla de stats acumuladas siguen mostrando
el histórico completo sin filtrar por temporada — filtrar esas vistas por
temporada (con selector de temporada, probablemente) es el sub-proyecto 2/3 del
plan, todavía no arrancado.

## Ranking por temporada (2026-08-26)

Segundo sub-proyecto de 3 (el primero fue la infraestructura base de arriba). Spec
completa: `docs/superpowers/specs/2026-08-25-ranking-por-temporada-design.md`.
`/ranking` y `/jugadores/{guid}` ahora respetan la temporada — **default en todo el
sitio: la temporada activa**, con `?season={id}` para cualquier temporada cerrada o
`?season=all` para el histórico completo. Cualquier link a un perfil de jugador sin
`?season=` explícito hereda la temporada activa; solo `/ranking` arma el link con
`?season=` explícito para que el perfil "herede" la que se estaba mirando.

- **Cálculo al vuelo, no una tabla `player_season_stats` nueva.** Mismo patrón que
  ya usaba `LeaderboardController::aggregateFromKills()` para el filtro de fecha
  manual (ahora eliminado, reemplazado por el selector de temporada) — un join
  `kills → rounds → matches` filtrado por `matches.season_id`, sin ningún
  acumulador que sincronizar. Decisión deliberada: el patrón "acumulador +
  corrección retroactiva" (`player_server_stats`/`player_map_stats`) ya causó
  varios bugs reales documentados en la bitácora de bugs más arriba (entradas 13,
  15, 16) — con ~50 partidas/~12k kills reales, calcular al vuelo es rápido y no
  necesita ningún mecanismo de sincronización aparte. `GameMatch::scopeForSeason($seasonId)`
  (nuevo) centraliza "qué partidas cuentan para esta temporada" — `$seasonId` es un
  id real o el string literal `'all'` — y lo consumen tanto `LeaderboardController`
  como `PlayerController`. Las tablas pre-calculadas `player_server_stats`/
  `player_map_stats`/`players.kills_total` etc. **no se tocan ni se borran** — solo
  dejan de leerse desde estas dos vistas.
- **Bug heredado corregido de una:** `aggregateFromKills()` nunca excluía partidas
  abandonadas sin resultado real (a diferencia de las tablas pre-calculadas, que sí
  lo hacían desde un fix anterior de esta misma sesión) — `scopeForSeason()` ahora
  excluye `GameMatch::abandonedWithoutConclusion()` siempre, sin importar la
  temporada elegida, incluido `season=all`.
- **`player_weapon_picks.season_id`** (columna nueva, mismo patrón que
  `matches.season_id` — nullable a nivel de esquema, asignada una sola vez en
  `ParseCod2Log`) hizo falta porque "arma más equipada" era un acumulador puro sin
  ninguna referencia temporal. Con `season=all`, toma el pick con más `picks` de
  cualquier temporada individual, no la suma entre temporadas — límite aceptado a
  propósito, "todo el historial" ya es la vista de excepción.
- **El filtro de fecha manual (`from`/`to`) se sacó de la UI de `/ranking`** — el
  selector de temporada lo reemplaza. La navegación por sesión específica de un
  mapa jugado varias veces (antes date-pills clickeables) también cambió: ahora
  siempre muestra la sesión más reciente automáticamente dentro de la temporada
  elegida (con un contador "N sesiones en esta temporada"), sin leer `from`/`to` de
  la URL — decisión ya tomada en el spec de este sub-proyecto (no una regresión;
  ver ese documento, sección "Axis/Allies panel").
- **Alcance del perfil de jugador:** todas sus secciones respetan la temporada —
  números principales, mejores mapas (`KillAggregator::aggregateByMap()`, nuevo),
  arma favorita, arma más equipada, team-kills, últimas bajas/muertes — no solo un
  subconjunto.
- **`/especialidades` sigue sin temporada** (fuera de alcance, sub-proyecto 3 de 3,
  todavía no arrancado) — sigue mostrando el histórico completo sin filtrar.
- Implementado con `subagent-driven-development` (5 tasks de código + esta). TDD en
  cada task: `tests/Feature/PlayerWeaponPickSeasonTest.php` (2 casos),
  `tests/Feature/LeaderboardSeasonTest.php` (4 casos),
  `tests/Feature/PlayerShowSeasonTest.php` (8 casos, incluye cobertura explícita de
  exclusión cross-temporada para arma favorita/team-kills/últimas muertes/mejores
  mapas, agregada en una ronda de fix tras la revisión). Verificado en un entorno
  aislado en el VPS (`/root/sdd_baseline`, sin PHP local en la máquina de
  desarrollo) antes de desplegar.

## Especialidades por temporada (2026-08-26)

Tercer y último sub-proyecto del plan de temporadas (el primero fue la infraestructura
base, el segundo `/ranking` y `/jugadores/{guid}`). Spec completa:
`docs/superpowers/specs/2026-08-26-especialidades-por-temporada-design.md`. **22 de
las 25 páginas de `/especialidades` ahora respetan la temporada elegida** — mismo
contrato de URL que `/ranking`: default la temporada activa, `?season={id}` para
cualquier temporada cerrada, `?season=all` para el histórico completo.

- **Fuera de alcance a propósito: `bombs`, `damage`, `disconnects`.** Estos tres
  contadores son acumuladores puros (`player_server_stats`/`player_map_stats`) sin
  ninguna tabla de detalle por línea de log de la que reconstruirse por temporada
  (ver "Chat y eventos de partida" más arriba, "no se implementaron" para
  `Damage;`/`Bomb;`/`Disconnected;`) — no hay forma de saber retroactivamente a qué
  temporada perteneció cada bomba/desconexión ya contada. Siguen mostrando el
  histórico completo, sin selector de temporada.
- **`SpecialtyController::resolveSeason()`** (nuevo, mismo contrato que ya usan
  `LeaderboardController`/`PlayerController` del sub-proyecto 2) centraliza
  `[$seasons, $seasonId, $matchIds]` para las 22 páginas. `sdKills()` (el helper
  interno ya existente) ahora exige `$matchIds` como segundo argumento.
- **`mapKings`** se reescribió sobre `KillAggregator::topByMap()` (nuevo) en vez de
  `PlayerMapStat` — mismo criterio de siempre (códigos de mapa crudos, sin fusionar
  variantes).
- **`rango()`** se reescribió sobre `KillAggregator::aggregate()` en vez de
  `PlayerServerStat` — mismo patrón que ya usaban los métodos del Grupo A
  (`grenades`, `headshots`, `friendlyFire`, `efficiency`, `bashCalls`).
- **`countries()`** sigue sin selector de servidor (nunca lo tuvo) — ahora solo
  cuenta jugadores con al menos un kill o muerte dentro de las partidas de la
  temporada elegida, en vez de "cualquier jugador con IP conocida alguna vez".
- **Equipos (el balanceador de equipos por rango) queda fuera de este trabajo — no
  por decisión de alcance, sino porque ese módulo (`PlayerRankCalculator.php`,
  `TeamBalanceController.php`) no existe en este repo git.** Se desplegó alguna vez
  directo al VPS desde otra máquina de desarrollo y nunca se comiteó — mismo gap ya
  conocido de otras features de este proyecto (ver "Deploy", sección "Alguien puede
  desplegar desde otra máquina sin pasar por esta conversación"). Efecto real: el
  `PlayerRankCalculator.php` que corre en producción sigue leyendo el acumulado
  histórico completo (`PlayerServerStat`), no la temporada activa — hoy coincide con
  `/especialidades/rango` porque solo existe "Temporada 1" (nunca cerrada), pero en
  cuanto el dueño cierre una temporada por primera vez, Equipos y `/especialidades/rango`
  van a mostrar rangos distintos para el mismo jugador. Se resuelve solo cuando
  Equipos se sincronice con git.
- Implementado con `subagent-driven-development`, mismo worktree que el
  sub-proyecto 2 (`ranking-por-temporada`). TDD en cada task de código:
  `tests/Feature/Specialties/GroupASeasonTest.php` (5 casos), `SuicidesSeasonTest`
  (implícito en Grupo B), `GroupCSeasonTest.php` (5 casos), `GroupDSeasonTest.php`
  (6 casos), `MapKingsSeasonTest.php` (2 casos), `GroupFSeasonTest.php` (2 casos),
  `CountriesSeasonTest.php` (1 caso). Verificado en un entorno aislado en el VPS
  (`/root/sdd_baseline`) antes de desplegar.

## Equipos traído a git y unificado con /especialidades/rango (2026-08-27)

El balanceador de equipos por rango (`/equipos`, ver el `CLAUDE.md` de la
máquina de desarrollo para el diseño original del 2026-08-25) vivía **solo en
el VPS** — nunca se había comiteado a git (el mismo gap ya documentado en
"Ranking por temporada" y "Hallazgo grave..." más abajo). Se trajo a este
repo trayendo los 6 archivos reales desde producción sin cambiarlos
(`app/Support/TeamBalancer.php`, `app/Http/Controllers/TeamBalanceController.php`,
`tests/Feature/TeamBalancerTest.php`, `resources/views/team-balance.blade.php`,
`resources/views/partials/team-balance.blade.php`) y reconciliando los 3
archivos que YA existían en git pero les faltaba la integración de Equipos que
ya corría en el VPS (`routes/web.php` — ruta `/equipos`; `routes/web.php` es el
mismo archivo cuya mezcla causó el "Hallazgo grave" de más abajo, así que se
verificó **byte a byte contra producción antes y después** de este cambio, sin
overlap con nada más; `Admin\ConsoleController::show()` — sugerencia de
Equipos en la consola admin; `resources/views/admin/console.blade.php` — el
`@include('partials.team-balance', ...)`).

**El único archivo que SÍ cambió de comportamiento real: `PlayerRankCalculator.php`.**
Antes leía `PlayerServerStat` (acumulado histórico completo, sin ningún
concepto de temporada) — desde el sub-proyecto 2 de temporadas,
`/especialidades/rango` ya no lee esa tabla (reescrito sobre
`KillAggregator::aggregate()` scopeado por `$matchIds`), así que los dos
consumidores del mismo score A-E habían quedado divergiendo: Equipos con el
histórico completo, `/rango` con la temporada elegida. Esto se señaló
explícitamente en la revisión final del plan de "especialidades por
temporada" como un riesgo real y pendiente ("en cuanto exista una segunda
temporada, Equipos y `/rango` van a mostrar rangos distintos para el mismo
jugador") — resuelto directamente a pedido del dueño, en vez de quedar como
deuda técnica.

- `PlayerRankCalculator::calculateForServer(Server $server, int|string|null $seasonId = null)`
  — `$seasonId` nuevo, nulo por default (resuelve a `Season::current()->id`,
  ya que Equipos no tiene selector propio, siempre pide la activa implícita o
  explícita). Reescrito internamente para usar `KillAggregator::aggregate()`
  + `GameMatch::forSeason($seasonId)` — la matemática de percentiles/quintiles
  (70% K/D + 30% win rate) no se tocó, solo de dónde sale `$stats`.
- **`SpecialtyController::rango()` ya no duplica la lógica** — ahora es una
  sola línea: `PlayerRankCalculator::calculateForServer($server, $seasonId)->values()`.
  Antes tenía ~115 líneas de percentiles/quintiles copiadas casi
  exactas del calculator (el propio calculator fue extraído de una versión
  vieja de `rango()` el 2026-08-25, y desde entonces las dos copias vivían
  por separado). Unificados para que no puedan volver a desincronizarse.
- `TeamBalanceController::index()` no necesitó cambios — ya llamaba
  `PlayerRankCalculator::calculateForServer($server)` sin segundo argumento,
  así que hereda la temporada activa automáticamente.
- TDD: `tests/Feature/PlayerRankCalculatorSeasonTest.php` (3 casos — sin
  segundo argumento usa la temporada activa; con el id de una temporada
  cerrada da esos datos; `/rango` y el calculator coinciden exacto en la
  misma temporada, el punto entero de la unificación). Verificado junto a
  `tests/Feature/TeamBalancerTest.php` (4 casos, sin cambios, solo
  reconfirmado que sigue pasando) y toda la suite (112 tests/428
  assertions, 1 fallo preexistente conocido) en el clon descartable del VPS
  antes de desplegar.
- Desplegado a producción: solo `PlayerRankCalculator.php` y
  `SpecialtyController.php` tenían cambio de comportamiento real (los otros
  5 archivos reconciliados quedaron byte-a-byte idénticos a lo que ya corría
  en el VPS, confirmado con `sha256sum` de todo `app/`/`resources/`/`routes/`
  antes de tocar nada). Verificado en producción: `/rango`, `/rango?season=all`,
  `/equipos` responden `200`.

## Bombas, daño y desconexiones por temporada (2026-08-27)

Últimas 3 de las 25 páginas de `/especialidades` que quedaban sin selector de
temporada (`bombas`, `daño`, "Se Fueron a Media Ronda"). Spec completa:
`docs/superpowers/specs/2026-08-27-bombas-dano-desconexiones-por-temporada-design.md`.

- **Motivo por el que quedaron fuera del sub-proyecto anterior:** las tres leen
  `player_server_stats.bomb_plants`/`bomb_defuses`/`damage_dealt`/`damage_taken`/
  `mid_round_disconnects` — acumuladores puros, sin ninguna tabla de detalle por
  evento (a diferencia de `kills`). El parser ya leía `Bomb;`/`Damage;`/
  `Disconnected;` del log (`ParseCod2Log::recordBomb()`/`recordDamage()`/
  `recordDisconnect()`), pero solo sumaba a ese acumulador plano por servidor, sin
  ningún vínculo a la partida.
- **Tabla nueva `player_match_extras`** (una fila por jugador+partida, mismos 5
  contadores) — poblada por `ParseCod2Log::bumpServerStatExtra()` **en paralelo**
  al acumulador plano existente, que no se toca ni cambia de comportamiento. No
  hizo falta tocar el parseo de las líneas en sí, solo agregar `$currentRound->match_id`
  a los 3 call-sites que ya invocaban `bumpServerStatExtra()`.
- **Limitación real e inevitable, no un defecto de este diseño:** solo se puede
  atribuir a una temporada específica lo jugado **desde este deploy en
  adelante** — nada de lo jugado antes (incluida la Temporada 1 actual, que ya
  lleva partidas desde el 9 de agosto) se puede reconstruir por temporada, el
  detalle granular nunca se guardó. Sigue existiendo únicamente bajo
  `?season=all`, que sigue leyendo `player_server_stats` sin cambios — el
  histórico completo real, sin ningún hueco.
- `bombs()`/`damage()`/`disconnects()` ganan la misma rama por `$seasonId` que
  el resto de `/especialidades`: `'all'` lee `PlayerServerStat` (código
  idéntico al de antes de este cambio); cualquier temporada específica lee
  `PlayerMatchExtra` agregado, filtrado por `GameMatch::where('server_id',
  ...)->whereIn('id', $matchIds)->pluck('id')` (dos pasos porque
  `player_match_extras` no tiene columna `server_id` propia).
- **Sin cambios de vista.** `resources/views/specialties/ranking.blade.php`
  (la plantilla compartida de estas 3 páginas) ya tenía `@isset($seasonId)
  @include('partials.season-selector', ...) @endisset` desde el fix urgente
  del sub-proyecto anterior (los 500 de estas mismas 3 páginas) — el selector
  aparece solo con que el controller pase `seasons`/`seasonId`.
- TDD: `tests/Feature/PlayerMatchExtraTest.php` (2 casos — relaciones,
  constraint único), `tests/Feature/ParseCod2LogExtrasTest.php` (1 caso —
  `Bomb;`/`Damage;`/`Disconnected;` reales pueblan `player_match_extras` con
  el `match_id` correcto, y `player_server_stats` sigue actualizándose exacto
  igual que antes), `tests/Feature/Specialties/ExtrasSeasonTest.php` (4 casos
  — exclusión de temporada vieja en las 3 páginas + `?season=all` cae al
  total histórico real, no a la suma de `player_match_extras`). Suite
  completa: 122 tests/464 assertions, 1 fallo preexistente conocido, sin
  regresiones.

## Variantes de mapa combinadas (2026-08-19)

`MapCatalog::normalize()` ya existía para que `mp_dawnville_fix` y
`mp_dawnville_sun` (mismo mapa real, distinto código de variante subida por la
comunidad) se etiqueten igual ("St. Mere Eglise, France"). Pero varias pantallas
todavía agrupaban/filtraban por el código **crudo**, así que el mismo mapa
aparecía duplicado con distinto conteo — reportado en "Mejores mapas" (perfil de
jugador) y en las pestañas de `/ranking`. Mismo problema para Carentan
(`_fix`/`_bal`).

**Dos arreglos separados, mismo patrón:**

- `MapCatalog::mergeVariants()` (nuevo) — suma kills/deaths/teamkills de todas las
  variantes de un mapa real en un solo item. Usado en
  `PlayerController@show` para `$player->mapStats`.
- `LeaderboardController::buildMapGroups()` — ahora agrupa por
  `MapCatalog::normalize($map)` en vez del código crudo. Cada grupo trae
  `->dates` (fechas combinadas) y `->codes` (los códigos crudos que lo componen).
  `$map` en el controller siempre se normaliza al leerlo de la URL
  (`MapCatalog::normalize($request->query('map'))`), y se calcula `$mapCodes`
  (el array de códigos crudos del grupo activo) para pasarlo a cada `whereIn`
  (antes eran `where('map', $map)` sueltos).

**Gotcha a tener en cuenta si se toca esto de nuevo:** el código normalizado
(`$map`, ej. \`mp_dawnville\`) sirve para la URL/pestaña/label, pero **nunca**
para filtrar `rounds.map` — esa columna solo tiene los códigos crudos
(\`mp_dawnville_fix\`, \`mp_dawnville_sun\`), \`mp_dawnville\` pelado no
existe ahí. Cualquier filtro contra \`rounds.map\`/\`kills\` tiene que usar
\`\$mapCodes\` (o el equivalente \`map_codes\` que arma \`mergeVariants()\`
para la vista del jugador), no \`\$map\`. Ya paso una vez en esta misma sesión:
el primer intento de esto dejó el boton de detalle de kills en \`/ranking\`
mandando \`map=mp_dawnville\` (normalizado) al endpoint \`/kills/{guid}\`, que
no encontraba ninguna ronda porque esa columna nunca tiene ese valor exacto —
se corrigió pasando \`\$mapCodes\` unidos por coma en vez de \`\$map\`.
\`KillDetailController\`/\`TeamkillController\` ya aceptan \`map=codigo1,codigo2\`
(\`explode(',', \$map)\` + \`whereIn\`) desde el fix de "Mejores mapas".

## Cara a cara en el popover de bajas (2026-08-23)

El popover que se abre al clickear el número de kills (\`data-kills-trigger\`,
compartido con el de fuego amigo — ver \`openDetailsPopover()\` en
\`layouts/app.blade.php\`) lista las víctimas con cuántas veces cada una cayó. Cada
fila con una víctima real (no bot) ahora es clickeable y revela, sin pedir nada más
al server, cuántas veces esa misma víctima mató de vuelta al jugador — mismo
alcance de filtros (server/mapa/partida/fecha) que el resto del popover.

\`KillDetailController::index()\` ya traía la dirección "jugador mató a X" con una
sola query agrupada; se agregó una segunda query agrupada (misma función
\`\$applyScope\` reusada, atacante/víctima invertidos) para la dirección contraria,
acotada con \`whereIn('attacker_player_id', \$victimIds)\` a solo los jugadores que
ya aparecen en la lista — ambas direcciones viajan en la misma respuesta JSON
(\`{victim, count, reverse}\`), el frontend solo esconde/muestra la fila
\`[data-reverse-row]\` al hacer click, no hay una request nueva por fila. Los bots
(\`victim_player_id\` nulo, agrupados por nombre) traen \`reverse: null\` y sus filas
no son clickeables — no tiene sentido "cara a cara" contra un bot indistinguible.

Verificado contra datos reales de producción antes de desplegar (2026-08-22/23):
DESTINATION#ZHAIKS mató a hardoso 19 veces, hardoso mató a ZHAIKS 7 — coincide con
lo reportado por el dueño.

## Crisis de disco y bug de vinculación de demos (2026-08-23)

El disco llegó a **98% de uso (354MB libres de 14GB)**, un día después de haber
arrancado ya ajustado (93%, ver nota en "Servidores temporales self-service" más
arriba). Investigación completa antes de tocar nada:

- **No había archivos huérfanos.** Se comparó cada archivo en
  `storage/app/private/demos/` contra `demos.file_path` en la base — coincidencia
  exacta 1:1, sin sobrantes de ningún lado. Los ~2.5GB de demos eran actividad real
  dentro de la ventana de retención, no basura.
- Los `.iwd` de `/home/gameserver/1.3/puG/main/` (3.7GB) son los assets base del
  juego — fijos, no tocar.
- **Bug real encontrado en `DemoMatchResolver`** (la clase que decide a qué partida
  pertenece un demo subido, ver "Subida automática de demos por HWID" más arriba): la
  rama que busca por abreviatura de mapa en el nombre del archivo no tenía **ningún**
  tope de antigüedad (a diferencia del fallback por tiempo, que sí rechaza cualquier
  partida de más de 3 horas). Si ese mapa puntual no se volvía a jugar en días, un
  demo terminaba pegado a una partida vieja sin relación real. Confirmado con
  casos reales: demos de Burgundy/Railyard/Matmata subidos 1-3 días después del 19-20
  de agosto quedaron vinculados a partidas de esos días en vez de a la partida real
  (o a ninguna, si no había una partida real cercana).

  Fix en dos vueltas (la primera quedó mal, ver comentario en el código):
  1. Se agregó un tope de 6 horas hacia atrás a la rama de búsqueda por mapa (más
     generoso que las 3h del fallback, porque esta rama existe justamente para
     sesiones de grabación largas que el fallback no cubre) más el mismo margen de
     90s hacia adelante que ya tenía el fallback.
  2. **Intento fallido**: al rechazar una partida vieja, el código caía al fallback
     genérico (sin filtro de mapa) — un demo de Railyard sin partida cercana de
     Railyard terminó pegado a una partida de **Burgundy** solo por cercanía de
     horario. Se corrigió para que, si hay una pista de mapa confiable, nunca se
     caiga a un mapa distinto — mejor dejar el demo sin partida (`match_id` null,
     "huérfano" hasta que se revise a mano) que adivinar mal.

  Se re-resolvieron los 129 demos existentes en producción con la lógica corregida
  (`DemoMatchResolver::resolve()` contra cada fila) — 7 quedaron corregidos (2 de
  Burgundy reasignados a su partida real, 5 de Railyard/Matmata desvinculados por no
  tener partida real cercana). Esto fue un backfill manual único vía tinker, **no**
  corre automático — `demos:reconcile-matches` solo revisa demos de los últimos 10
  minutos (ver esa sección), así que un bug de este tipo en datos viejos no se
  autocorrige con el fix de código solo, hay que re-correrlo a mano si se sospecha
  algo similar de nuevo.

- **`demo_retention_days` bajado de 3 a 2** (`settings` tabla, vía
  `Admin\SettingController` o directo — se hizo por tinker en este incidente), a
  pedido del dueño, para que el uso de disco de demos se estabilice más bajo de forma
  sostenida. Se corrió `demos:prune-old` manualmente una vez con la nueva retención
  (borró 51 demos de golpe) en vez de esperar al cron diario.
- **Limpieza de journal de systemd** (~104MB liberados) y `apt-get clean` — ver la
  nota de journald en "Systemd + sudoers + permisos" más arriba para el límite
  permanente que se agregó (`SystemMaxUse=300M`) para que esto no se vuelva a
  acumular sin límite.

Resultado: **354MB → 1.3GB libres (98% → 91%)**. Sigue siendo un margen ajustado —
si se sigue achicando, no asumir que es de nuevo lo mismo, repetir el diagnóstico
completo (`df -h`, `du -sh` por directorio) antes de actuar.

## Respaldos de base de datos: poda de duplicados del mismo día (2026-08-27)

`RunDatabaseBackup` (`backup:run`, cron diario a las 3am vía
`Schedule::command('backup:run')->dailyAt('03:00')`, además disparable a mano desde
`/adm_cod2/respaldos` y automático antes de cada `restore()`/`import()`) ya borraba
respaldos con más de `RETENTION_DAYS` (10) días — pero un mismo día calendario podía
acumular varios (el automático de las 3am + cualquier manual/pre-restore de ese
mismo día), notado por el dueño al ver 2-3 respaldos con la misma fecha.

Lógica de poda extraída a `app/Support/BackupPruner::prune(int $retentionDays): int`
— el comando (`RunDatabaseBackup`) en sí no es testeable en el entorno de tests
(SQLite en memoria, sin `mysqldump`/`mysql` reales), así que la extracción permite
testear la lógica de poda sola. Dos reglas, en este orden: (1) borra lo que ya pasó
los 10 días, sin importar el día; (2) de lo que queda, si un mismo día calendario
tiene más de un respaldo, deja solo el **más reciente** de ese día (más completo/
actualizado). TDD: `tests/Feature/BackupPrunerTest.php` (3 casos). Limpieza única
de los duplicados ya existentes en producción corrida a mano vía tinker el mismo
día del deploy (no espera al próximo `backup:run` automático).

**De paso: `2` agregado a las opciones de retención de demos
(`/adm_cod2/demos`).** El backend (`SettingController::update()`) ya aceptaba
cualquier entero de 1 a 365 — el límite estaba solo en el `<select>` hardcodeado
de la vista (`[3, 5, 10, 20, 30, 60, 90]`, sin el 2), a pedido del dueño.

## Rango (/especialidades/rango y Equipos): mínimo de partidas subido, mínimo de bajas eliminado (2026-08-27)

A pedido del dueño: `PlayerRankCalculator::MIN_MATCHES` subido de 5 a 10, y
`MIN_KILLS` (antes 20) **eliminado del todo** — ya no hay ningún piso de bajas
para calificar a un rango A-E, solo la cantidad de partidas jugadas. Como
`SpecialtyController::rango()` y `TeamBalanceController`/Equipos comparten este
mismo calculator (unificados el mismo día, ver más arriba), el cambio aplica a
los dos consumidores de una sola vez, sin poder desincronizarse.

Un jugador con pocas bajas pero muchas partidas ahora sí entra al ranking —
antes quedaba afuera aunque tuviera 10+ partidas si no llegaba a 20 bajas. El
texto "Mínimo N partidas jugadas..." en `/rango` y el aviso de "?" en el widget
de Equipos (`partials/team-balance.blade.php`) se actualizaron para no
mencionar más un mínimo de bajas que ya no existe.

TDD: `tests/Feature/PlayerRankCalculatorSeasonTest.php` ganó 2 casos nuevos
(un jugador con pocas bajas pero 10+ partidas ahora califica; un jugador con
9 partidas —una menos que el nuevo mínimo— sigue sin calificar aunque tenga
muchas bajas). Los fixtures existentes de `GroupDSeasonTest`/
`PlayerRankCalculatorSeasonTest` que asumían el viejo mínimo de 5 partidas se
subieron a 10 (mismas proporciones de kills/muertes, mismos K/D esperados).
Suite completa: 127 tests/475 assertions, 1 fallo preexistente conocido, sin
regresiones.

## Bug real: filas fantasma en `players` por guid corrupto en tránsito (2026-08-28)

Reporte del dueño: jugadores "MOKOS" (`CHILEAN.'.MQZ`, `MOKOS`, `MOKOS RELOAD`)
se repetían en el ranking pese a la identidad por HWID/guid. Investigado con la
BD real: ese caso puntual **no es un bug** — son 3 `guid` genuinamente distintos
con kills reales bajo cada uno (confirmado desde `Kill;` del log, no solo RCON),
timeline de alias continuo sin huecos, mismo rango de IP — la misma persona
cambió de HWID real 3 veces en 3 semanas (reinstalación, spoofing, u otra causa
de hardware; no hay forma de que el modelo de identidad por guid lo evite ni de
fusionarlo automáticamente).

Buscando "más casos así" se encontró un bug real y separado, más grande:
**27 de 69 filas de `players` (39%) eran fantasmas** con `kills_total=0` y
`deaths_total=0`, cada una con el mismo `last_name_plain` que un jugador real y
activo, vista minutos aparte, y un `guid` a 1-2 caracteres de distancia
(Levenshtein) del guid real — ej. jugador real `GenuineF` (guid `-2119631637`,
1156 kills) con 4 fantasmas (`-211963163`, `-211931637`, `-211961637`,
`-211631637`) en menos de 2 horas la misma sesión.

**No es una segunda identidad real:** `guid` es FNV-1a de 32 bits del HWID2
(`HwidHasher.php`) — el efecto avalancha de un hash garantiza que un hardware
genuinamente distinto produce un número irreconocible, nunca "el mismo número
menos un dígito". Validado contra el código fuente real de CoD2x
(`github.com/callofduty2x/CoD2x`): el comando `status` no está reimplementado
por CoD2x, delega en el binario original de Activision vía `ASM_CALL` a una
dirección fija — el punto exacto de la corrupción queda fuera del código legible
(closed-source), pero es la misma familia de bug que los 3 guards que ya existían
en `upsertPlayer()` para RCON "status" corrupto (guid fuera de rango 2026-08-14,
ping ausente 2026-08-19, pérdida de color 2026-08-22) — un caso más que ninguno
cubría: un guid truncado que sigue siendo un int32 válido.

**Importante, corregido en el diseño del fix a partir de una observación del
dueño:** IP compartida NO es señal de nada (CGNAT, VPN, o dos personas reales
en la misma casa — caso real confirmado: `Ekkaia`/`dianaaaaaaaaaaa`, pareja,
mismo IP, ambos con cientos de kills reales cada uno). El fix nunca mira la IP.

**Fix** en `ParseCod2Log::upsertPlayer()`: antes de crear una fila `players`
nueva, si ya existe un jugador con el mismo `last_name_plain` visto en los
últimos 5 minutos cuyo guid está a distancia de edición ≤2 del guid entrante,
se usa el guid conocido en vez de crear uno nuevo — no hace falta que el
jugador esté conectado desde la misma IP.

TDD: `tests/Feature/ParseCod2LogPhantomGuidTest.php` (3 casos) — guid casi
idéntico + mismo nombre + segundos después no crea fila nueva (RED confirmado
sin el fix); guid genuinamente distinto para el mismo nombre sigue creando fila
nueva (guardia de regresión para el caso MOKOS real); guid casi idéntico pero
con nombre distinto también sigue creando fila nueva. Verificado en un clone
descartable en el VPS antes de desplegar: RED sin el fix, GREEN con el fix,
suite completa 132 tests/492 assertions, mismos 2 fallos preexistentes sin
relación (`ExampleTest`, `CountriesSeasonTest` — confirmado que fallan igual
contra el baseline sin este cambio).

**Pendiente, no resuelto todavía:** las 27 filas fantasma que ya existían en
producción antes de este fix no se limpiaron (0 kills/deaths, no hay estadística
real que perder al borrarlas) — el dueño pidió arreglar la causa raíz primero,
la limpieza queda para una próxima sesión si la pide.

## Fusionar jugadores (admin) + guid visible en el perfil (2026-08-28)

Seguimiento directo de la investigación de MOKOS/Dav1Ds (ver más arriba): el
dueño pidió una forma de fusionar a mano los perfiles de un mismo jugador real
con varios `guid` (HWID inestable entre sesiones — reinstalación de CoD2x,
formateo, etc., confirmado real con al menos 2 casos), sin tener que tocar SQL
directo cada vez.

- **`app/Support/PlayerMerger.php`** — el merge de verdad. Mueve `kills`
  (`attacker_player_id`/`victim_player_id`), `demos`, `bans`, `chat_messages`
  (sin unique constraint, solo repunta la FK), y **suma** en vez de repuntar en
  las 4 tablas con `unique(player_id, X)` — `player_map_stats` (ojo: el unique
  real es `(player_id, server_id, map)` desde el multi-server del 2026-08-10, NO
  `(player_id, map)` como en la migración original de la tabla — un descuido
  fácil si se lee solo esa primera migración), `player_server_stats`,
  `player_weapon_picks`, `player_match_extras` — porque el destino casi siempre
  ya tiene su propia fila para el mismo mapa/server/arma/partida. `player_aliases`
  se dedupea por `name` exacto (el unique real), quedándose con el
  `last_seen_at` más reciente entre las dos filas si colisiona. Los acumuladores
  de `players` (`kills_total`, etc.) se suman; `first_seen_at`/`last_seen_at` se
  amplían al rango combinado. **El guid/nombre histórico en `kills`/
  `chat_messages`/`bans` no se reescribe** — es el registro fiel de lo que el
  log dijo en su momento, no la identidad "actual". Todo en una sola
  `DB::transaction()` con `lockForUpdate()`; los jugadores fuente se borran al
  final.
- **`/adm_cod2/jugadores/fusionar`** (`PlayerMergeController`, nuevo, bajo
  Moderación en el nav admin) — busca por nombre actual **o por cualquier alias
  histórico** (`orWhereHas('aliases', ...)`), porque el nombre visible actual de
  un jugador casi nunca es el que tenía cuando compartía identidad con otro
  perfil — confirmado en vivo: buscar "MOKOS" solo encuentra 2 de las 3 filas
  reales de ese jugador, porque la tercera (`CHILEAN.'.MQZ`, guid `1055257276`)
  usó el alias real **"MOCOS"** (con C, no con K) — la herramienta ayuda a
  encontrar candidatos por texto, pero no reemplaza el juicio del admin
  (chat/IP/timeline) para casos así.
- UI: checkbox por fila para marcar "es esta persona" + radio para elegir cuál
  queda como destino (server-side se exige que el destino esté entre los
  marcados, si no `back()->withErrors(...)`), contador en vivo de
  kills/deaths combinados (JS liviano, sin dependencias), confirm() antes de
  enviar. Auditado vía `AdminAction::record('players.merge', ...)` (mismo
  patrón que el resto del panel admin).
- **guid visible en el perfil público** (`/jugadores/{guid}`, chico y en gris
  bajo el nombre) y en cada fila de la tabla de fusión — es lo único que el
  sitio realmente guarda de la identidad del jugador (ver sección "Identidad
  del jugador" más arriba): el HWID2 crudo nunca se persiste en `players`, solo
  se usa de paso en la subida de demos (`HwidHasher::hwidToGuid()`) para
  resolver a qué guid pertenece.
- TDD: `tests/Feature/Support/PlayerMergerTest.php` (5 casos: suma de totales
  de por vida, fusión de 3 perfiles a la vez —caso real de MOKOS—, kills
  repuntados sin reescribir guid/nombre histórico, suma correcta de
  `player_map_stats` sin violar el unique real, dedupe de alias) +
  `tests/Feature/Admin/PlayerMergeControllerTest.php` (4 casos: guest
  redirigido a login, búsqueda por alias encuentra el perfil aunque el nombre
  actual sea otro, merge real mueve datos y audita, destino fuera de los
  seleccionados rechazado). Verificado en un clone descartable del VPS: 9/9
  tests nuevos, suite completa 141 tests/517 assertions, mismos 2 fallos
  preexistentes sin relación.
- Desplegado a producción, rutas confirmadas con `route:list`, ruta protegida
  por `auth` confirmada con `curl` (redirige a login sin sesión), búsqueda real
  contra "MOKOS" probada por tinker (sin ejecutar ningún merge real todavía —
  la fusión de los perfiles reales de MOKOS/Dav1Ds queda para cuando el dueño
  la dispare desde el panel).
- **UI rehecha a drag-and-drop (mismo día, a pedido del dueño).** La tabla de
  checkboxes/radios se reemplazó por dos columnas: resultados como tarjetas
  arrastrables (`draggable="true"`, vainilla JS, sin librería) a la izquierda,
  y una "zona de destino" a la derecha donde se sueltan las que son la misma
  persona — cada tarjeta soltada muestra una ★ (toca para marcarla como
  destino, la primera entra por default) y una × para sacarla del grupo.
  Fallback de accesibilidad/touch: cada tarjeta también tiene un botón `+`
  que hace exactamente lo mismo que arrastrarla (drag-and-drop nativo de
  HTML5 no anda en touch ni es accesible por teclado). El submit sigue
  mandando los mismos `source_ids[]`/`target_id` de siempre — la UI nueva
  solo cambia cómo se arman esos campos client-side (inputs hidden generados
  por JS), así que el controller/`PlayerMerger` no cambiaron y los tests de
  backend siguen siendo válidos tal cual. Verificado renderizando la vista
  real con datos reales de "MOKOS" vía `tinker` antes de desplegar (sin
  tocar el navegador, sin poder correr Dusk/JS en este entorno) — confirma
  que compila y trae el guid/nombre correctos en los `data-*`, no que el
  drag-and-drop en sí funcione en un navegador real (eso queda para que el
  dueño lo prueble).
- **guid en el perfil público reajustado (mismo día):** color subido de
  `slate-600` (casi ilegible sobre el tema oscuro) a `text-cyan-400` — mismo
  color que "Ver todos los mapas", a pedido del dueño tras verlo en vivo.

## Borrar un jugador (admin) — kills quedan en el historial, sin dueño (2026-08-28)

Seguimiento directo del módulo de fusión de arriba: el dueño preguntó si
`/adm_cod2` tenía algo para borrar un jugador cualquiera (no solo limpiar el
`ip` como ya hacía "Países") — no existía, y se armó. **Primera versión vivía
como un botón dentro de `/adm_cod2/jugadores/fusionar`; el dueño pidió
sacarlo de ahí y hacerlo un módulo independiente**, con el listado completo
de jugadores (no depender de buscar primero) y doble confirmación en vez de
una sola — rehecho así mismo día, antes de que la primera versión llegara a
usarse.

- **`Admin\PlayerDeleteController`** (nuevo, controller propio — antes vivía
  el método `destroy()` dentro de `Admin\PlayerController`, se movió acá para
  que el módulo sea de verdad independiente, no solo visualmente). `index()`
  lista **todos** los jugadores (`Player::with('aliases')->orderByDesc('last_seen_at')->get()`,
  sin filtrar por `ip` como sí hace `/paises`) — mismas columnas que la
  tarjeta de fusión (guid, alias, kills/deaths, primera/última vez visto)
  pero en tabla completa. `destroy()` (misma lógica que antes, sin cambios):
  `$player->delete()` sin cascada custom a mano (a diferencia de
  `PlayerMerger`, que sí tuvo que sumar filas para no romper los
  `unique(player_id, X)`) — no hace falta acá porque el esquema ya está
  diseñado para esto desde que se crearon las tablas:
  `kills.attacker_player_id`/`victim_player_id`, `chat_messages.player_id`,
  `bans.player_id`, `demos.player_id` son todos `nullOnDelete()`, así que el
  kill/mensaje/ban/demo sobrevive con el `guid`/nombre crudo tal cual estaba
  (misma lógica que ya usan los kills de un bot, `guid=0` sin `player_id`) —
  el jugador borrado simplemente deja de sumar a ningún ranking, pero no
  desaparece del historial de partidas. Lo que sí se pierde
  (`cascadeOnDelete()`, correcto — no tiene sentido sin el jugador):
  `player_aliases`, `player_map_stats`, `player_server_stats`,
  `player_weapon_picks`, `player_match_extras`.
- Rutas propias, separadas de fusión: `GET /adm_cod2/jugadores/borrar`
  (listado) y `DELETE /adm_cod2/jugadores/borrar/{player}` (bind por `id`,
  no por `guid` — mismo patrón que `/paises/{player}`). Link "Borrar
  jugadores" agregado al dropdown Moderación, al lado de "Fusionar
  jugadores" pero como entrada separada.
- **Doble confirmación real** (a pedido explícito, no una sola `confirm()`
  como el resto del panel): dos `confirm()` de JS encadenados en el submit
  del form — el primero resume qué se pierde, el segundo es la advertencia
  final de "no se puede deshacer"; cualquiera de los dos que se cancele
  aborta el submit (`e.preventDefault()`).
- Auditado vía `AdminAction::record('players.destroy', ...)`, guardando
  nombre/guid/kills en la descripción porque la fila va a desaparecer (sin
  cambios respecto a la primera versión).
- TDD: `tests/Feature/Admin/PlayerDeleteControllerTest.php` (3 casos: guest
  redirigido a login tanto en `index` como en `destroy`; el listado muestra
  jugadores sin `ip` — a diferencia de `/paises`, que los excluye; borrar
  deja el kill con `attacker_player_id=null` pero `attacker_guid`/
  `attacker_name` intactos, borra el alias, audita). El test viejo
  (`PlayerDestroyTest.php`, apuntaba a la ruta `admin.players.destroy` ya
  eliminada) se borró, no solo se dejó sin usar. Verificado en un clone
  descartable del VPS: 12/12 tests relacionados en verde, suite completa 144
  tests/531 assertions, mismos 2 fallos preexistentes sin relación.
  Desplegado, rutas confirmadas con `route:list`, listado verificado
  renderizando con los 69 jugadores reales vía `tinker`.
- **Ajustes chicos al mismo módulo, mismo día:** buscador client-side (JS,
  sin recargar) por nombre/alias/guid agregado arriba de la tabla — el
  listado sigue completo por default, el buscador solo acota filas
  (`.classList.toggle('hidden')` sobre `<tr>`, sin round-trip al server). La
  columna visible "Alias usados" se sacó de la tabla (los alias se siguen
  indexando para la búsqueda, con `data-search` en cada `<tr>`, solo dejaron
  de mostrarse como columna aparte).
- **Bug de nav corregido, mismo día (reportado por el dueño): los 3
  dropdowns del header admin (Contenido/Moderación/Sistema) no se cerraban
  entre sí** — cada `<button>` solo miraba su propio `<div>` con
  `classList.toggle('hidden')`, así que abrir "Sistema" nunca cerraba
  "Moderación" si ya estaba abierto, quedaban varios abiertos a la vez.
  Reemplazado por un listener delegado en `layouts/admin.blade.php`: cierra
  todos los `.admin-dropdown` antes de abrir el que se tocó, y los cierra
  también al hacer click afuera de cualquiera (`document.addEventListener`
  a nivel body). Los 3 botones pasaron de 3 atributos `data-*-toggle`
  distintos con `onclick` inline a uno solo `data-dropdown-toggle="<id>"`.

## Borrado en masa de jugadores sin actividad (admin) (2026-08-28)

Seguimiento directo de "Borrar un jugador" de arriba: el dueño pidió un botón
para borrar de una todos los perfiles con 0 kills Y 0 deaths — exactamente
las filas fantasma del bug de guid corrupto documentado más arriba (28 en
producción a la fecha; el fix en `upsertPlayer()` evita que sigan creciendo,
pero no limpió retroactivamente las que ya existían).

- **Respaldo tomado ANTES de tocar código**, a pedido explícito del dueño —
  `php artisan backup:run` corrido a mano en el VPS
  (`backups/cod2_stats_2026-08-28_114614.sql.gz`), visible y restaurable
  desde `/adm_cod2/respaldos` si algo sale mal probando el botón nuevo. No
  se ejecutó el borrado en masa real contra producción — el dueño lo prueba
  él mismo desde el panel.
- **`PlayerDeleteController::destroyZeroActivity()`** (nuevo) — mismo
  `delete()` de siempre, sin lógica de cascada custom (el esquema ya está
  diseñado para esto, ver la sección de arriba), solo que en bloque: un
  `whereIn` en vez de N deletes individuales. Lee la lista de afectados
  ANTES de borrar (para poder auditar quiénes eran — la fila desaparece) y
  usa `AdminAction::record('players.destroy-bulk-zero-activity', ...)`
  guardando la lista completa de nombres+guids en la descripción.
- Ruta `DELETE /adm_cod2/jugadores/borrar/masivo-sin-actividad` — **registrada
  ANTES** de `DELETE /adm_cod2/jugadores/borrar/{player}` en `routes/web.php`
  a propósito: si quedara después, Laravel matchearía `{player}` con el
  string literal `"masivo-sin-actividad"` en vez de llegar nunca a la ruta
  de borrado en masa (mismo tipo de trampa de orden de rutas ya conocida en
  Laravel con wildcards).
- UI: caja roja arriba del todo en `/adm_cod2/jugadores/borrar` con el
  conteo real (`{{ $zeroActivityCount }}`, calculado en el controller sobre
  los jugadores ya cargados) — solo aparece si hay al menos 1. Misma doble
  confirmación que el borrado individual.
- TDD: `tests/Feature/Admin/PlayerDeleteControllerTest.php` ganó 3 casos
  (guest redirigido a login; el borrado en masa solo toca jugadores con
  kills=0 Y deaths=0 —guarda uno con solo kills, uno con solo deaths, y uno
  real, los 3 sobreviven—; correrlo sin nada que borrar no rompe nada ni
  audita de más). Verificado en un clone descartable del VPS: 6/6 tests
  relacionados en verde, suite completa 147 tests/542 assertions, mismos 2
  fallos preexistentes sin relación. Desplegado, ruta confirmada con
  `route:list` (orden correcto, la específica antes que la wildcard),
  botón verificado renderizando con el conteo real (28) vía `tinker`.
- **Probado por el dueño el mismo día, resultado limpio:** 28 fantasmas
  borrados, quedaron 39 jugadores reales (69→39), conteo de 0/0 volvió a 0.
  Auditado con la lista completa de nombres+guids en `admin_actions`. No
  hizo falta restaurar el backup.
- **Ajustes al mismo módulo, mismo día, a pedido del dueño:** filas
  numeradas (`#`, columna nueva) y columnas Kills/Deaths/Visto sacadas —
  después, en un pedido aparte, Kills/Deaths **volvieron** pero ahora
  ordenables (headers clickeables, toggle mayor↔menor, primer click siempre
  desc — mismo criterio que `/ranking`) más medallas 🥇🥈🥉 para el top 3 por
  kills (mismo emoji/criterio que `/ranking` y el dashboard). La medalla se
  calcula en el controller sobre el top-3-por-kills real, no por la posición
  visual de la fila — así se mantiene correcta sin importar cómo esté
  ordenada la tabla en un momento dado (JS solo reordena el DOM, no
  recalcula medallas). `index()` pasó a ordenar por `kills_total` desc por
  default (antes `last_seen_at` desc).
- **Nav público:** el link dentro del dropdown "RANKING" se llamaba
  "Ranking" (repetía la palabra del botón) — renombrado a "Estadísticas",
  mismo pedido, mismo día.
- **Nav admin, bug corregido (reportado por el dueño):** los 3 dropdowns del
  header (Contenido/Moderación/Sistema) no se cerraban entre sí — cada
  `<button>` solo miraba su propio `<div>`, así que abrir uno nunca cerraba
  los demás. Reemplazado por un listener delegado (`data-dropdown-toggle`
  unificado + clase `.admin-dropdown` compartida) que cierra todos antes de
  abrir el tocado, y cierra todo al hacer click afuera.
- **Chiste a pedido del dueño:** ícono de burro (`public/burro.png`, subido por
  el dueño desde `icon-icons.com` — bloqueaba la descarga directa por curl sin
  un header `Referer`, se resolvió agregándolo) al lado de la medalla de
  dtN.harek (guid `1127155189`) cuando entra al top 3 en `/ranking` y en el
  dashboard — hardcodeado por guid, no un sistema nuevo. 11px, ajustado a pedido
  para que combine con el tamaño de la medalla. Se evaluó un ícono de Che
  Guevara para "hardoso" con un link de `pngegg.com`, pero ese sitio está
  detrás de un challenge anti-bot de Cloudflare que `curl` no puede resolver
  (a diferencia de `icon-icons.com`, que solo pedía el header) — descartado,
  el dueño lo sube directo cuando esté el módulo de admin para esto.

## Bug real y serio: kills/deaths de DM (y potencialmente HQ/CTF) contaban para el ranking de SD (2026-08-28)

Reportado por jugadores en vivo: "cuando he jugado DM/HQ/CTF/etc las muertes
se registran en el ranking, esto está mal, solo debe funcionar para SD".

**Causa raíz, en `ParseCod2Log::recordKill()`:** SD manda `RoundStart;` al
empezar cada ronda, así que un cambio de gametype siempre pasa por
`openRound()` (que ya compara `$currentMatch->gametype !== $gametype` y abre
partida nueva si corresponde) — pero **DM nunca manda `RoundStart;` en
absoluto** (documentado desde antes en "Cuándo se crea una partida"). El único
código que manejaba esto abría una ronda nueva solo si `$currentRound` era
`null` — si en cambio quedaba una ronda de SD ya *terminada* (`ended_at`
puesto, de la partida jugada justo antes) todavía en memoria, el chequeo viejo
`$currentRound->ended_at && $pendingGametype !== 'dm'` dejaba pasar un
fallthrough silencioso: cualquier `Kill;` de la sesión de DM que viniera
después seguía atribuyéndose a esa ronda vieja de SD, con
`$countsTowardRanking = $currentRound->gametype === 'sd'` evaluando `true`
para kills que en realidad eran de DM.

**Confirmado en producción antes de tocar nada:** `round_id=1555`
(`match_id=107`, tag `sd`, partida real jugada 2026-08-26 23:44 → 2026-08-27
00:30) tenía **86 kills con `occurred_at` entre 2026-08-27 20:05 y 20:30 —
casi 20 horas después** de que la partida terminara. Los nombres involucrados
(`hardoso` vs `^^55h€ll^^66~fíŠh¦^^55Shyne™`, arma `kar98k_mp` en las 86, 1v1
constante) coinciden exactamente con el patrón de una sesión de DM real, no
con SD.

**Fix:** la condición pasó de comparar `$pendingGametype !== 'dm'` (hardcodeado
a un solo gametype) a comparar `$pendingGametype === $currentRound->gametype`
— si coinciden, sigue siendo el mismo hueco de ready-up de la propia partida
(se descarta, comportamiento sin cambios); si NO coinciden, el gametype
realmente cambió y hace falta abrir una ronda nueva de verdad (mismo
`openRound()` que ya usa el camino de `RoundStart;`), sin importar si el modo
nuevo es `dm` específicamente o cualquier otro que tampoco mande
`RoundStart;`. Más general y más correcto que el hardcode original, cubre
`HQ`/`CTF`/etc. también si alguno resulta tener el mismo comportamiento.

**Corrección retroactiva de los datos ya afectados** (respaldo tomado antes
con `php artisan backup:run`,
`backups/cod2_stats_2026-08-28_121900.sql.gz`): las 86 kills de `round_id=1555`
se movieron a un match/round nuevo creados a mano (`match_id=110`, `round_id=1630`,
`gametype='dm'`, timestamps tomados de los propios `occurred_at` de esas
kills) en vez de dejarlas mal tagueadas dentro del match 107 (que sigue
siendo una partida real de SD, con sus otras rondas intactas). Después,
`php artisan cod2:recalculate-stats` (el comando ya existente, `StatsRecalculator`
— reconstruye `kills_total`/`deaths_total`/etc. desde cero a partir de
`kills`+`rounds` filtrando `gametype='sd'`) recalculó todo el server de una,
sin aritmética manual propensa a error. Efecto medido: `hardoso` kills_total
1137→1097 (-40), deaths_total 818→773 (-45); `Shyne` (guid `-499334653`, el
que estaba activo en esas fechas) kills_total 84→39 (-45), deaths_total
94→53 (-41) — los números cruzan entre sí (kills de uno ≈ deaths del otro)
como corresponde a un 1v1.

TDD: `tests/Feature/ParseCod2LogDmAfterSdTest.php` (2 casos) — RED confirmado
contra el código viejo reproduciendo el bug exacto (una ronda de SD que
termina, seguida de un `InitGame:` a `dm` sin `RoundStart;`, seguida de una
`Kill;`: el código viejo la contaba como SD); GREEN con el fix; guardia de
regresión para el caso legítimo que el fix no debe romper (una kill perdida
en el propio hueco de ready-up de SD, mismo gametype, sigue descartándose).
Verificado en un clone descartable del VPS: suite completa 149 tests/552
assertions, mismos 2 fallos preexistentes sin relación. Desplegado, corrida
manual de `cod2:parse-log` sin errores nuevos en `laravel.log`.

## Batch de feedback de jugadores (2026-08-28) — 4 features en una sesión

El dueño compartió 9 puntos de feedback recibidos de jugadores. Triage
completo hecho antes de tocar código (algunos ya estaban resueltos, otros
necesitaban más contexto del dueño) — de los que se aprobaron para
implementar, 4 se construyeron esta misma sesión:

- **`/bombas` gana un tab "Desactivadas"** (`?stat=defuses`) — antes el
  ranking de esa página solo rankeaba a quien planta; quien desactiva solo
  aparecía como número total arriba (`statCards`), sin ranking de jugadores
  propio. `specialties.ranking` (la vista genérica que ya usan ~10 páginas de
  specialties) ganó un bloque `@isset($tabs)` opcional, reusable por
  cualquier otra página futura que necesite el mismo patrón de toggle.
- **`/rivalidades` gana un tab "Con granadas"** (`?type=grenades`) — misma
  lógica de emparejamiento (`attacker_player_id`/`victim_player_id`
  agrupados, roster deduplicado por par sin importar dirección), filtrando
  `is_grenade=true` con el piso bajado de 3 a 2 bajas (mucho más raras que
  las bajas en general). El cálculo de "arma favorita" del cara a cara se
  saltea para este tab (siempre diría "granada", no aporta nada).
- **`/ranking` gana columnas "Días" y "Kills/día"** — pedido explícito:
  contar días distintos conectado (no reconexiones) para promediar con las
  kills y dar un ranking más justo. Se decidió derivarlo de
  `kills.occurred_at` (días con al menos un kill O una muerte) en vez de
  armar un tracker de conexiones nuevo — no existe ninguna tabla que guarde
  cada `Connected;` como evento propio (`upsertPlayer()` solo actualiza
  `last_seen_at` al vuelo), así que un tracker nuevo solo tendría datos
  desde el día que se agregara; esto en cambio funciona retroactivo con
  todo el historial ya cargado. Implementado en `KillAggregator::aggregate()`
  (afecta a TODOS sus consumidores: `/ranking`, el perfil de jugador, la
  tabla axis/allies de cada partida — todos ganan `days_played`/
  `kills_per_day` en cada row, aunque por ahora solo `/ranking` los muestra).
  **Bug real encontrado y corregido en el camino:** `Eloquent\Collection::merge()`
  dedupea por primary key, no concatena como lista — con `selectRaw()` sin
  seleccionar `id`, cada fila es un modelo con `getKey()===null`, así que
  `merge()` de dos colecciones de 1 fila cada una colapsaba a **0 filas**
  (confirmado con un `DebugTest` temporal antes de encontrar la causa).
  `concat()` es el método correcto para "juntar dos listas", que es lo que
  se necesitaba acá.
- **Grid por ronda en `/partidas/{id}`** — el pedido más grande: "quién mató
  a quién, con qué arma, o si hubo un clutch", para revisar jugadas raras o
  sacar clips. Antes la página de partida solo mostraba el resumen total
  (kills/deaths por jugador para el match entero), nunca desglosado por
  ronda. Nuevo: fila de botones "Ronda 1, 2, 3..." que abren/cierran un
  panel con la tabla de kills de esa ronda (hora, atacante → víctima, arma
  con ícono, headshot, fuego amigo) — un solo panel abierto a la vez, mismo
  patrón de listener delegado que los dropdowns del nav admin (2026-08-28,
  más arriba). **Reusa exactamente la misma definición de clutch que
  `SpecialtyController::clutches()`** (roster completo de
  `round.winner_guids` menos quien murió ESA ronda, un solo sobreviviente
  en un roster de 3+), aplicada ronda por ronda dentro de una sola partida
  en vez de por-jugador-y-temporada — mismo cálculo, alcance distinto, sin
  duplicar la lógica. La kill que dio el clutch se resalta en la tabla.
- TDD en los 4: `ExtrasSeasonTest` (+2 casos, tab de defuses),
  `RivalriesGrenadesTest` (2 casos), `KillAggregatorDaysPlayedTest` (4
  casos), `MatchRoundDetailsTest` (3 casos). Cada uno verificado en su
  propio clone descartable del VPS antes de desplegar (suites completas
  153/157/160 tests en distintos momentos de la sesión, mismos 2 fallos
  preexistentes sin relación en todas). El grid por ronda además verificado
  contra una partida real de producción (`match_id=109`, 36 rondas, 24
  clutches detectados) antes de dar el feature por cerrado.
- **Puntos del feedback NO implementados esta sesión** (decisión del dueño
  o pendientes de más contexto — ver conversación del 2026-08-28 para el
  detalle completo de los 9 puntos originales): mínimo de partidas del
  rango (10→9, es una decisión del dueño, no de los jugadores, no se tocó
  sin su confirmación explícita); "sistema de conteo más justo para el
  rango" (el dueño pidió explícitamente no tocar esto); "las kills cambian
  en el ranking" (confirmado por el dueño que ya quedó resuelto con el fix
  de DM del mismo día, ver arriba); el perfil de jugador ya mostraba "arma
  favorita"/"arma que más usa" desde antes de este batch — no quedó claro
  si el jugador que lo pidió no lo vio o si la queja era específicamente
  sobre la sección de "últimas kills/muertes" de abajo, sin tocar hasta
  tener más claridad.
- **Seguimiento el mismo día:** el dueño sí confirmó el #5 después — `MIN_MATCHES`
  bajado de 10 a 9 (`app/Support/PlayerRankCalculator.php`). Fixture de
  `PlayerRankCalculatorSeasonTest.php` que probaba el límite (antes 9
  partidas = no califica) bajado a 8 partidas para seguir probando el
  límite real ("uno menos que el mínimo").
- **Rivalidades devuelta al menú Especialista → Combate** (mismo día) —
  estaba oculta a pedido sin razón registrada en el código; el dueño la
  quiso de vuelta al ver el tab nuevo de granadas.
- **Headshots/Granadas del ranking general ahora son clickeables, igual que
  Kills** (mismo día, reporte de un jugador) — reusan el mismo popover y el
  mismo endpoint `/kills/{guid}` que ya usaba la columna Kills
  (`KillDetailController`), solo con `?type=headshot|grenade` acotando el
  resultado — no un endpoint nuevo. La "cara a cara" (reverse count, cuántas
  veces esa víctima te mató de vuelta) también respeta el mismo `type`: sin
  esto, ver "a quién headshoteaste" pero la cara-a-cara mostrando cualquier
  baja de vuelta (no solo headshots) sería confuso. TDD:
  `KillDetailControllerTypeFilterTest.php` (4 casos). Verificado en un
  clone descartable junto con el cambio de `MIN_MATCHES`: suite completa
  164 tests/600 assertions, mismos 2 fallos preexistentes sin relación.
- **Línea de tiempo de rondas en `/partidas/{id}`** (pedido de un jugador,
  2026-08-28, completando el #8 del batch original — el grid por ronda ya
  estaba, faltaba esto) — una fila de cuadraditos, uno por ronda en orden,
  verde si ganó axis esa ronda específica, gris si ganó allies. **No** es el
  lado "actual" de nadie (eso ya existía, `TeamSideAnalyzer::splitByCurrentSide()`,
  un snapshot de todo el match) — los lados cambian de bando en el
  entretiempo, así que hacía falta el lado real DENTRO de cada ronda
  puntual. Nuevo `MatchController::roundWinningSide()`: mismo patrón de
  votación por mayoría que `TeamSideAnalyzer::sideScores()` ya usa para
  todo el match, acá acotado a las kills de una sola ronda (cualquier kill
  de esa ronda donde el atacante O la víctima esté en el roster ganador
  aporta un voto por el lado que esa persona tenía en ESA kill puntual).
  Cada cuadradito es un link que salta a esa ronda y la fuerza a abrirse
  (a diferencia de los botones "Ronda N" de abajo, que solo togglean).
  TDD: 3 casos nuevos en `MatchRoundDetailsTest.php` (mismo guid ganando
  como axis en una ronda y como allies en otra, prueba que no es "guid 1
  siempre = axis"; ronda sin ninguna kill involucrando al roster ganador
  → `null`, no adivina). Verificado en un clone descartable (suite completa
  167 tests/603 assertions, mismos 2 fallos preexistentes) y contra la
  partida real `match_id=109` (36 rondas, las 36 con lado determinado —
  17 axis, 19 allies — cero `null`).
- **Iteraciones el mismo día, a pedido del dueño:** colores de la línea de
  tiempo cambiados de verde/gris a axis=rojo/allies=azul (mismos tonos que
  ya usaban los paneles Axis/Allies), y agregado un badge de
  ganador/perdedor al final de la fila (reusa `$sideScores`, sin cálculo
  nuevo). Después, reorden completo de `/partidas/{id}`: badges (sin
  tocar) → línea de tiempo → tabla general → Tabla de Posiciones (axis/
  allies) → botón "Ver rondas" que abre el grid por ronda en un **popup**
  (antes vivía inline debajo de todo). Los cuadraditos de la línea de
  tiempo ahora abren el popup directo en la ronda clickeada en vez de
  hacer scroll a una sección inline — mismo `openRoundDetail()` de
  siempre, solo que ahora también des-oculta `#cod2-rounds-modal` primero.
  Sin cambios de controller en ninguna de las dos iteraciones, solo vista.

## Horas jugadas (reemplaza días), nav "Tabla de clasificación", y perfil de jugador ampliado (2026-08-29)

Batch de pedidos del dueño relayando feedback de jugadores.

- **`/ranking` cambia "Días"/"Kills por día" por "Horas"/"Kills por hora"** — pedido
  explícito: horas es "más preciso" que contar días distintos conectado. Nuevo
  `app/Support/PlaytimeCalculator.php`, extraído de `SpecialtyController::playtime()`
  (ya calculaba "segundos jugados" para `/horas-jugadas`) para reusar el mismo cálculo
  en `LeaderboardController` sin duplicar lógica — mismo proxy de siempre (duración de
  rondas SD donde el jugador tuvo al menos un kill o muerte), ahora sumando segundos en
  vez de contar días distintos. `days_played`/`kills_per_day` se sacaron por completo de
  `KillAggregator::aggregate()` (nadie más los consumía).
- **El dropdown del nav "RANKING" pasa a llamarse "TABLA DE CLASIFICACIÓN"** — pedido
  explícito, sin cambiar ninguna ruta (`route('leaderboard')` etc. sin tocar, solo el
  texto del botón).
- **Perfil de jugador (`/jugadores/{guid}`) gana 3 secciones nuevas:**
  - **Horas jugadas** — nueva card en la grilla de stats de arriba (ahora
    `md:grid-cols-7`), mismo `PlaytimeCalculator` de arriba pero sin scopear por
    server (`secondsByPlayer(null, $matchIds)` — el perfil es global, a diferencia de
    `/ranking`).
  - **Reemplaza "Últimas bajas"/"Últimas muertes"** (esa lista cronológica era "poco
    relevante" según el feedback real de un jugador) por dos columnas nuevas: **Armas**
    (desglose completo arma por arma con kills+headshots, no solo la "arma favorita" de
    arriba) y **Rivalidad** (verdugo 😈/presa favorita 🎯 — mismo par nemesis/victim que
    ya calcula `/rivalidades`, acotado a este jugador). `PlayerController::show()`
    perdió las queries de `$recentKills`/`$recentDeaths` — reemplazadas por
    `$weaponBreakdown`/`$topNemesis`/`$topVictim`.
  - **Win rate general** — 7ma card de la grilla de arriba. `app/Support/WinRateCalculator.php`
    (nuevo): para cada partida SD donde el jugador tuvo al menos un kill o muerte,
    reusa `TeamSideAnalyzer::winningRosterGuids()` (el mismo clustering por overlap de
    guids que ya calcula `final_score`) para decidir si el guid del jugador estaba en
    el roster ganador; partidas sin ganador determinable (empate/rondas insuficientes)
    no cuentan ni como jugadas ni como ganadas. **Arrancó como idea de agregar una
    columna de winrate POR MAPA en "Mejores mapas"** (pedido original del dueño) pero
    se cambió a un solo número agregado ("cambiar win rate por mapa por win rate
    general", mismo día) antes de implementarla — nunca se llegó a construir la
    versión por mapa.
  - **De paso se encontró que ya existía una página separada, `/win-rate`
    (`SpecialtyController::winRate()`, nav "Mapas y partidas"), con un cálculo de win
    rate YA general** (partidas ganadas/jugadas del server completo, no por mapa) **pero
    mal titulada "Win Rate por Mapa" en todos lados** (título de pestaña, `<h1>`, link
    del nav) desde que se creó — un mislabel preexistente, no introducido esta sesión.
    Corregido a "Win Rate General" (`resources/views/specialties/win-rate.blade.php`,
    el link del nav en `layouts/app.blade.php`) — cambio de texto puro, la lógica de
    `winRate()` no se tocó porque ya calculaba lo correcto. Este page-level "win rate
    general" (ranking de TODOS los jugadores del server, mínimo 3 partidas, sin scope
    de temporada propio más allá del selector que ya tenía) y el nuevo
    `WinRateCalculator` del perfil (temporada-scopeado como el resto del perfil, sin
    mínimo de partidas) son cálculos independientes que pueden diferir un poco entre sí
    (distinto criterio de "temporada" y de piso mínimo) — no se unificaron esta sesión,
    ver "Pendientes" si en algún momento hace falta.
- TDD: `tests/Feature/PlayerShowSeasonTest.php` — 2 tests que asertaban sobre las
  `recentKills`/`recentDeaths` removidas se reescribieron para probar el scope de
  temporada de `weaponBreakdown`/`topNemesis` en su lugar (mismos 8 casos totales del
  archivo, solo 2 reemplazados). `tests/Feature/Support/WinRateCalculatorTest.php`
  (nuevo, 5 casos: victoria cuenta, derrota cuenta como jugada pero no como ganada,
  promedio correcto entre varias partidas, partida sin ganador determinable se
  excluye, partidas sin participación del jugador se ignoran). Verificado en un clone
  descartable del VPS: 166/168 tests, mismos 2 fallos preexistentes sin relación
  (`ExampleTest`, `CountriesSeasonTest`). Desplegado a producción, verificado con
  `curl` contra un jugador real (`/jugadores/-1106466662`): card de Win rate muestra
  "45.7% (16/35)".

## Iconos de armas sacados, "Mapas ganados" en el perfil, y navegación ‹/› en el popup de rondas (2026-08-29)

Tres pedidos del dueño el mismo día del batch anterior.

- **Iconos de armas sacados de dos lugares** (a pedido explícito, "eso elimina"):
  la tabla "Armas" del perfil de jugador (`players/show.blade.php`) y la columna
  Arma del grid por ronda en `/partidas/{id}` (`matches/show.blade.php`) — ambos
  usaban `WeaponCatalog::iconUrl()`, que sigue existiendo (no se tocó el
  método) por si algún otro lugar la necesita en el futuro, solo se dejó de
  invocar en estos dos `<img>`.
- **"Ver todas las armas"** — la tabla "Armas" del perfil ahora trunca a 5 filas
  (antes mostraba el desglose completo sin límite) con un botón que abre un
  modal con la lista completa, mismo patrón exacto que ya usan "Ver todos los
  mapas" y "Ver todos los alias" en esa misma página.
- **Nueva sección "Mapas ganados"** en el perfil de jugador — partidas
  jugadas/ganadas/win rate por mapa (no solo el número agregado de la card de
  arriba). `WinRateCalculator::forPlayer()` se refactorizó para compartir su
  query de "partidas SD donde el jugador participó, con ganador determinable"
  vía un método privado nuevo (`decidedMatchesForPlayer()`), y
  `WinRateCalculator::byMapForPlayer()` (nuevo) agrupa esas mismas partidas por
  `MapCatalog::normalize($match->map)` — variantes comunitarias del mismo mapa
  real (`mp_toujane_fix`/`mp_toujane_bal`) se fusionan en una sola fila, mismo
  criterio que `MapCatalog::mergeVariants()` ya usa para "Mejores mapas". Mismo
  patrón de truncar a 5 + modal "Ver todos los mapas" que el resto de la
  página. **Nombre coincide por casualidad con la página ya existente
  `/mapas-ganados`** (`SpecialtyController::mapsWon()`, nav "Mapas y
  partidas") — son cálculos independientes y con alcance distinto (esa es un
  ranking global de "quién ganó más veces cada mapa", esta sección es el
  propio historial de un jugador) — no se unificaron ni se renombró ninguna
  de las dos.
- **Navegación ‹/› en el popup de rondas de `/partidas/{id}`** (pedido del
  dueño: antes había que cerrar el popup y volver a buscar el siguiente
  cuadradito de la línea de tiempo) — dos botones en el header del modal
  (`cod2-round-prev`/`cod2-round-next`, deshabilitados en los extremos) más
  flechas del teclado (←/→, solo mientras el modal está abierto). JS vanilla,
  sin librerías: recorre el mismo array de `[data-round-jump]` en el orden ya
  existente del DOM (mismo orden que `$roundDetails`), sin pedir nada nuevo al
  server. El listener de click de cada cuadradito de la línea de tiempo se
  reescribió para compartir la misma función (`cod2ShowRoundByIndex()`) que
  los botones ‹/›, en vez de tener dos caminos separados para "abrir una
  ronda".
- TDD: `tests/Feature/Support/WinRateCalculatorTest.php` ganó 2 casos
  (desglose por mapa correcto entre partidas ganadas/perdidas en distintos
  mapas; fusión de variantes de parche comunitario del mismo mapa real).
  Verificado junto a `MatchRoundDetailsTest`/`PlayerShowSeasonTest` y la
  suite completa en un clone descartable del VPS: 168/170 tests, mismos 2
  fallos preexistentes sin relación. Desplegado a producción, verificado con
  `curl` contra un jugador real: sin `<img>` de armas en el perfil ni en el
  grid de rondas, sección "Mapas ganados" con datos reales (ej. "St. Mere
  Eglise, France · 9 jugadas · 6 ganadas · 66.7%"), botones/JS del popup de
  rondas presentes en el HTML de una partida real.

## Historial de partidas en el perfil de jugador (2026-08-29)

Seguimiento directo de "Mapas ganados" de arriba — el dueño preguntó si esa
sección mostraba también el historial partida por partida, no solo los
totales agregados por mapa. No lo hacía; se agregó.

- `WinRateCalculator::decidedMatchesForPlayer()` (privado, ya existía como
  base compartida de `forPlayer()`/`byMapForPlayer()`) ya devolvía exactamente
  la forma que hacía falta (`{match, won}` por partida) — `matchHistoryForPlayer()`
  (nuevo, público) es solo ese mismo resultado ordenado por `started_at` desc,
  sin agrupar por mapa.
- Nueva sección "Historial de partidas" en el perfil, entre "Mapas ganados" y
  "Armas/Rivalidad" — lista de partidas (badge Ganada/Perdida, mapa, "hace
  cuánto"), cada fila linkeando a `/partidas/{id}`. Trunca a 10 con modal "Ver
  todo el historial", mismo patrón que el resto de la página.
- TDD: `tests/Feature/Support/WinRateCalculatorTest.php` ganó 1 caso (orden
  cronológico correcto + flag de ganada/perdida por partida; `makeMatch()`
  ganó un parámetro opcional `$startedAt` para poder variar la fecha entre
  partidas de prueba). Verificado junto a `PlayerShowSeasonTest` y la suite
  completa en un clone descartable del VPS: 169/171 tests, mismos 2 fallos
  preexistentes sin relación. Desplegado a producción, verificado con `curl`
  contra un jugador real — filas reales con badge/mapa/fecha correctos,
  linkeando a la partida real (`/partidas/107`, etc.).

## Reorden del perfil de jugador y Rivalidad eliminada (2026-08-29)

El emparejamiento de la entrada anterior (Armas+Historial, Mapas ganados+Rivalidad)
se veía "descuadrado" según el dueño — reordenado explícitamente: **Mejores
mapas/Alias usados** (sin cambios), **Historial de partidas/Mapas ganados**, y
**Armas sola** (ancho completo, ya no emparejada con nada).

**Rivalidad (verdugo 😈/presa favorita 🎯) se sacó del todo** — a pedido
explícito del dueño ("no es relevante"), pese a haber sido la idea original de
"la otra columna" de la entrada del 2026-08-29 sobre horas/armas/rivalidad. Se
quitó tanto la sección de la vista como el cálculo entero en
`PlayerController::show()` (`$topNemesisRow`/`$topVictimRow`/`$rivalIds`/
`$rivalPlayers`/`$topNemesis`/`$topVictim` — nadie más los consumía, no quedó
código muerto). El test que cubría su scope de temporada
(`test_top_nemesis_excludes_old_season_deaths`) y el helper `createDeath()` que
solo ese test usaba se borraron de `PlayerShowSeasonTest.php` en vez de dejarlos
huérfanos. Verificado en un clone descartable del VPS: 168/170 tests, mismos 2
fallos preexistentes sin relación. Desplegado, orden de secciones confirmado con
`curl` contra un jugador real.

## Muertes, Headshots y Kills con granada clickeables en el perfil + Armas por arma + Muertes en /ranking (2026-08-29)

El dueño reportó que en el perfil de jugador, a diferencia de Kills, las cards
"Muertes"/"Headshots"/"Kills con granada" no tenían ningún detalle al hacer
click — y que en la tabla "Armas", clickear el número de Kills o Headshots de
una fila tampoco mostraba nada (a diferencia del resto del sitio, donde
Headshots/Granadas ya eran clickeables desde el 2026-08-28). Después pidió que
lo mismo aplicara también a la columna Muertes de `/ranking`.

- **`KillDetailController::index()`** (el endpoint compartido `/kills/{guid}`
  que ya alimentaba Kills/Headshots/Granadas/Fuego amigo) ganó dos filtros
  nuevos, sin volverse un endpoint aparte:
  - **`?weapon=`** — acota el resultado a bajas con esa arma puntual. Usado
    por las columnas Kills/Headshots de la tabla "Armas" del perfil (y su
    modal "ver todas").
  - **`?direction=deaths`** — invierte atacante/víctima en la misma query:
    en vez de "a quién mató $player" (kills, comportamiento de siempre),
    "quién mató a $player" (deaths). La "cara a cara" (reverse count) se
    invierte junto con el resto — para `direction=deaths`, el reverse pasa a
    significar "cuántas veces `$player` mató de vuelta a esa persona" (antes
    era al revés). El código se generalizó con `$playerCol`/`$otherIdCol`/
    `$otherNameCol` en vez de tener las columnas `attacker_*`/`victim_*`
    hardcodeadas dos veces — la fórmula del reverse-count resultó ser
    simétrica entre las dos direcciones (swap de las mismas dos columnas),
    así que no hizo falta duplicar esa lógica tampoco.
- **`openDetailsPopover()`** (`layouts/app.blade.php`) gana el `kind`
  `'deaths'` — manda `&direction=deaths` al mismo endpoint, título "Muertes
  (N)" en rojo (a diferencia de Kills/Headshots/Granadas, en cian), el
  contador principal de cada fila también en rojo, y la etiqueta de la fila
  "cara a cara" cambia de "Te mató" (para kills/headshots/grenades) a "Lo
  mataste" en cian (para deaths) — mismo componente compartido, solo la
  semántica de qué color va en qué lado se invierte según la dirección.
- **Perfil de jugador**: las cards Muertes/Headshots/Kills con granada de la
  grilla de arriba ganan `data-deaths-trigger`/`data-headshots-trigger`/
  `data-grenades-trigger` (mismo patrón que Kills, que ya los tenía). La
  tabla "Armas" (y su modal "ver todas") gana `data-kills-trigger`/
  `data-headshots-trigger` con `weapon={{ $w->weapon }}` en `data-params`.
- **`/ranking`**: las 3 tablas de la página (general + paneles Axis/Allies)
  ganan `data-deaths-trigger` en la columna Muertes, mismo `$tkParams` que ya
  usaban Kills/Headshots/Granadas en esas mismas tablas.
- TDD: `tests/Feature/KillDetailControllerTypeFilterTest.php` ganó 3 casos
  (`direction=deaths` devuelve quién mató al jugador con el reverse
  invertido correctamente; sin el parámetro se comporta igual que antes —
  guardia de regresión; `weapon=` filtra solo las bajas con esa arma).
  Verificado junto a `KillDetailControllerSeasonTest` (sin cambios, sigue
  pasando — confirma que la generalización de columnas no rompió el
  comportamiento scopeado por temporada) y la suite completa en un clone
  descartable del VPS: 171/173 tests, mismos 2 fallos preexistentes sin
  relación. Desplegado a producción, verificado con `curl` contra datos
  reales: `/kills/{guid}?direction=deaths` devuelve "quién te mató más" con
  conteos reales, `/kills/{guid}?weapon=kar98k_mp` filtra correctamente, y
  las 3 páginas (perfil, `/ranking`, tabla Armas) muestran los botones
  nuevos con los parámetros correctos en el HTML real.

## ESC cierra popups, "Desempeño general por mapa" unificado, fecha real en Historial (2026-08-29)

Tres pedidos chicos del dueño, mismo día.

- **ESC cierra cualquier popup/modal abierto.** Antes solo se podía cerrar
  clickeando afuera o el botón "X" — ningún modal del sitio escuchaba la
  tecla. Un solo listener global nuevo en `layouts/app.blade.php` (base de
  toda página pública) cierra el popover de kills/teamkill
  (`#teamkill-popover`) y cualquier elemento cuyo id contenga `-modal`
  (`[id*="-modal"]`, no `$=` porque algunos ids llevan sufijo dinámico, ej.
  `dates-modal-2026-08` en `leaderboard.blade.php`) — cubre todos los modales
  existentes (perfil de jugador, partida, ranking) sin tener que repetir el
  listener en cada vista ni tocarlas.
- **"Mejores mapas" y "Mapas ganados" del perfil se unificaron en una sola
  tabla, "Desempeño general por mapa"** (Mapa/Kills/Muertes/Jugadas/Ganadas/
  Win rate) — antes mostraban el mismo mapa en dos tablas separadas, obligando
  a cruzar mentalmente los datos. `PlayerController::show()` arma
  `$mapPerformance` iterando `$player->mapStats` (la lista más completa —
  cualquier partida con al menos un kill/muerte del jugador, sin importar si
  llegó a un resultado real) y le suma el dato de
  `WinRateCalculator::byMapForPlayer()` cuando existe; un mapa sin ninguna
  partida con ganador determinable simplemente queda en 0 jugadas/0 ganadas,
  no desaparece de la tabla. `$player->mapStats` (la relación en sí) no se
  tocó — sigue siendo la fuente de datos, solo dejó de leerse directo desde
  la vista.
- **"Historial de partidas" cambia "hace 1 día" por fecha real** (`d/m/Y H:i`,
  mismo formato que ya usa el resto del panel — `admin/matches`, `admin/demos`,
  auditoría, etc.) — a pedido del dueño, en la tabla principal y en el modal
  "ver todo el historial".
- TDD: `tests/Feature/PlayerShowSeasonTest.php` ganó 2 casos (el merge junta
  kills/muertes con jugadas/ganadas/win rate correctamente para el mismo mapa;
  un mapa con kills reales pero sin partida con ganador determinable
  mantiene jugadas/ganadas en 0 en vez de desaparecer). Verificado junto a la
  suite completa en un clone descartable del VPS: 173/175 tests, mismos 2
  fallos preexistentes sin relación. Desplegado a producción, verificado con
  `curl` contra un jugador real: una sola tabla con los 6 datos combinados
  por mapa, y fechas reales (`26/08/2026 23:44`, etc.) en el historial.

## Pulido visual del perfil de jugador (2026-08-29)

El dueño pidió mejorar el diseño del perfil porque "se veía muy desordenado" —
usado el skill `ui-ux-pro-max` (búsqueda local de patrones UI/UX) para guiar la
revisión. La búsqueda `--design-system` con keywords genéricas de gaming devolvió
un match de estilo "3D & Hyperrealism" que no aplicaba (esta es una página densa
en tablas, no un hero/showcase) — se descartó y se reconsultó con
`--domain style` usando "analytics dashboard data dense stats", que sí acertó:
**Data-Dense Dashboard** (múltiples tablas/cards, padding mínimo, grid eficiente)
es el patrón real de esta página. Cambios aplicados, todos verificados con
`curl` contra producción real antes de darlos por buenos:

- **Historial de partidas: Resultado y Marcador ahora enlazan a la partida**,
  igual que Mapa (antes solo el mapa era clickeable, pedido explícito del
  dueño) — en la tabla principal y en el modal "ver todo el historial".
- **La línea "arma favorita / arma que más usa / fuego amigo" pasa a vivir
  dentro de una card** (`rounded-xl border border-slate-800 bg-panel`), igual
  que el resto de la página — antes era texto suelto flotando entre dos
  bloques de tarjetas, lo que contribuía a la sensación de desorden.
- **`weapon-modal` ("ver todas las armas") ensanchado de `max-w-md` a
  `max-w-lg`** — mismo ancho que los otros modales de 3+ columnas
  (`map-stats-modal`, `match-history-modal`), evitando que las columnas
  Kills/Headshots quedaran apretadas.
- **Descartado tras feedback en vivo:** se probó reagrupar la grilla de 7 stats
  de arriba (Kills→Win rate) en dos filas separadas ("Combate" 5 stats +
  "Actividad" 2 stats) para reducir la sensación de una fila plana de 7
  columnas — el dueño lo vio desplegado y pidió volver a la fila única
  original explícitamente ("no me gusta así"). Revertido antes de dejar el
  cambio, documentado acá para no repetir la misma prueba sin necesidad si se
  retoma el pulido visual más adelante.
- Cambio puramente de vista (`players/show.blade.php`), sin tocar
  `PlayerController` ni tests — no había lógica nueva que cubrir con TDD.
  Verificado en producción con `curl`: links de Resultado/Marcador presentes
  (80 ocurrencias en la página real), `weapon-modal` con `max-w-lg`, grilla de
  stats de vuelta a `md:grid-cols-7`.

## Reorden final del perfil: pares por afinidad de datos, no por nombre de sección (2026-08-29)

Seguimiento directo de la entrada anterior — el dueño pidió un emparejamiento
distinto al que había quedado: **"Desempeño general por mapa" + "Historial de
partidas"** lado a lado arriba, **"Alias usados" + "Armas"** lado a lado abajo
(antes: Desempeño+Alias arriba, Historial+Armas como filas sueltas de ancho
completo).

- Las dos tablas de la fila de arriba tienen 6 y 4 columnas respectivamente —
  a mitad de ancho cada una quedaban apretadas con el padding de siempre
  (`px-4`). Se redujo a `px-2`/`px-3` en esas dos tablas puntuales (no en
  Alias/Armas, que no lo necesitan) y los headers "Jugadas"/"Ganadas"/"Win
  rate" de Desempeño se abreviaron a "Jug."/"Gan."/"WR" con `title=""` para
  no perder el significado al pasar el mouse — mismo criterio de
  "abreviar solo donde el espacio realmente aprieta" en vez de abreviar toda
  la página parejo.
  El modal "ver todos los mapas" (con más ancho disponible) sigue con los
  labels completos sin abreviar.
- Ambas tablas de la fila de arriba pasan de `take(5)` a `take(4)` filas
  visibles — para que las dos cards queden con la misma altura al estar una
  al lado de la otra (antes tenían distinto número de filas visibles y se
  notaba el desnivel). Los umbrales de "¿mostrar el botón *ver todo*?"
  (`@if($matchHistory->count() > ...)`) se actualizaron a juego en los 3
  lugares que lo usan (tabla principal, botón, y el `@if` que envuelve el
  modal entero) — encontrado un bug real de paso: el botón usaba `> 4` pero
  el modal seguía envuelto en `> 5`, así que con exactamente 5 partidas el
  botón aparecía pero el modal no existía en el DOM (click sin efecto).
  Corregido antes de desplegar, no llegó a producción con esa inconsistencia.
- **"Guid: {{ guid }}"** reemplaza el label en minúscula "guid {{ guid }}"
  bajo el nombre del jugador (pedido explícito, solo en esta página — no se
  tocó el mismo patrón que existe en `admin/bans/index.blade.php` ni
  `admin/players/merge.blade.php`, fuera de alcance de este pedido).
- Verificado en producción con `curl`: orden de headers confirmado
  (Desempeño → Historial → Alias → Armas), datos reales renderizando
  correctamente en la tabla comprimida (`Toujane, Tunisia · 195 kills`, etc.),
  "Guid: -1106466662" presente.

## Popup de rondas anclado arriba + quien ganó cada ronda; /partidas muestra Axis/Allies (2026-08-30)

Dos seguimientos del mismo día, ambos vía `ui-ux-pro-max` (búsqueda local del
skill de UI/UX no encontró match especifico para "list row gap" — se avisó
explícitamente al dueño que la recomendación de layout era razonamiento
general, no un match de la base de datos del skill).

- **Popup de rondas de `/partidas/{id}`:** el fix anterior (mismo día,
  `h-[85vh]` fijo) evitaba que las flechas ‹/› se movieran al cambiar de
  ronda, pero dejaba un área vacía grande en rondas con pocos kills — el
  dueño lo reportó como "un fondón azul sin sentido". Causa: forzar una
  altura fija resuelve el problema de raíz equivocada (centrado vertical);
  el fix correcto es anclar el popup **arriba** (`items-start` + `pt-16` en
  vez de `items-center`) con `max-h-[calc(100vh-8rem)]` en vez de altura
  fija — el header nunca se mueve (siempre al mismo offset desde arriba) Y
  el popup se ajusta al contenido real de cada ronda, sin área vacía.
- **De paso, quién ganó cada ronda** (antes solo visible como color en los
  cuadraditos de la línea de tiempo) se agregó como badge de texto junto a
  "Ronda N" dentro del popup — mismo dato (`data-round-winner` en el link de
  la línea de tiempo), sin pedir nada nuevo al servidor.
- **`/partidas` (listado): el marcador ("19-17") no decía a qué lado
  correspondía cada número** — el dueño lo pidió agregado. Nuevo
  `TeamSideAnalyzer::winningSideForMatch()` — versión liviana de
  `sideScores()` para listados con muchas partidas: vota
  `winningRosterGuids()` contra el último lado observado de cada guid
  directamente desde una query chica de kills (`attacker_guid`/
  `attacker_team`/`victim_guid`/`victim_team`), **sin construir el
  leaderboard completo de cada partida** (que sí hace falta en
  `MatchController::show()` vía `splitByCurrentSide()`, pero sería
  desproporcionado para 20 filas de un listado). `MatchController::index()`
  hace **una sola query batcheada** para las 20 partidas de la página
  (`whereIn('match_id', ...)`), no una por fila — mismo patrón de batching
  que ya usan `KillDetailController`/`TeamkillController`. Badge rojo
  "Axis"/azul "Allies" junto al marcador en `/partidas`.
- TDD: `tests/Feature/TeamSideAnalyzerWinningSideForMatchTest.php` (5 casos
  — axis gana, allies gana, sin ganador determinable, guids bot en el
  roster ganador no cuentan como voto, se usa el último lado observado por
  guid). El caso de "roster ganador solo con bots" necesitó dos intentos:
  el primero (13 rondas de bots seguidas de 5 rondas reales) asumía mal el
  orden de clustering y dio `axis` en vez de `null` — corregido construyendo
  el escenario en el orden real que dispara la regla "ronda ganada
  íntegramente por bots se clasifica al roster opuesto del último
  clasificado" (ver bitácora de bugs, entrada 17). Verificado junto a la
  suite completa en un clone descartable del VPS: 178/180 tests, mismos 2
  fallos preexistentes sin relación. Desplegado a producción, verificado con
  `curl` contra partidas reales: badges "Axis"/"Allies" correctos junto al
  marcador real (`19-17`, `13-7`, etc.).

## Módulo de íconos personalizados por jugador (2026-08-28)

Generaliza el chiste hardcodeado de dtN.harek (guid `1127155189`, un burro al
lado de su medalla en el top 3 de `/ranking` y del dashboard — ver entrada del
batch de feedback de jugadores más arriba) a cualquier jugador, subido desde
un módulo de admin nuevo en vez de vivir pegado al código de cada view.

- **`players.icon_path`** (columna nueva, nullable) + `Player::getIconUrlAttribute()`
  (`$player->icon_url`, null si nunca subió uno).
- **`App\Support\PlayerIcon::store()`** — "el ícono siempre debe ajustarse"
  (pedido explícito del dueño): cualquier imagen que suba un admin se
  re-escala con GD **antes** de guardarse, nunca se confía en el archivo
  original tal cual. Fit-within (nunca upscale, nunca crop a cuadrado) a un
  máximo de 128px en el lado más largo, preservando la relación de
  aspecto — el burro real (294×512, no cuadrado) se muestra con
  `width:11px;height:auto`, así que forzar un cuadrado hubiera sido
  distinto del comportamiento ya validado en producción. Siempre se
  re-codifica a PNG con canal alpha preservado (`imagesavealpha`), guardado
  en `storage/app/public/player-icons/{player_id}.png` — mismo id fijo
  siempre, así que subir uno nuevo pisa el anterior sin dejar huérfanos.
  `PlayerIcon::destroy()` borra el archivo y limpia la columna.
- **`Admin\PlayerIconController`** (`/adm_cod2/jugadores/iconos`, nav bajo
  Moderación) — mismo patrón que `/jugadores/borrar`: listado completo de
  jugadores con buscador client-side, sin depender de buscar primero. Cada
  fila tiene su propio `<form multipart>` con file input + botón "Subir",
  más un botón "Quitar" si ya tiene ícono. Auditado vía `AdminAction`
  (`players.icon-upload`/`players.icon-remove`).
- **`leaderboard.blade.php`/`dashboard.blade.php`**: el `@if($row->player->guid == 1127155189)`
  hardcodeado se reemplazó por `@if($row->player->icon_url)` — mismo lugar
  exacto (al lado de la medalla, solo top 3), ahora dirigido por datos en
  vez de un guid quemado en el código.
- **El burro real de harek se migró al sistema nuevo**, no quedó como caso
  especial: `PlayerIcon::store()` corrido una vez por `tinker` en
  producción contra el `public/burro.png` original — quedó re-escalado a
  74×128 (mismo aspecto 0.58, dentro del límite de 128px) en
  `storage/player-icons/4.png`. `public/burro.png` (el archivo hardcodeado
  original) se borró del repo y del VPS — confirmado sin referencias
  restantes en ningún `.blade.php` antes de borrarlo.
- TDD: `tests/Feature/Support/PlayerIconTest.php` (5 casos — resize
  correcto preservando aspecto con tolerancia por redondeo de píxeles,
  nunca upscalea una imagen chica, reemplaza el ícono anterior en el mismo
  path, destroy borra archivo+columna, accessor null sin ícono) +
  `tests/Feature/Admin/PlayerIconControllerTest.php` (5 casos — guest
  redirigido en las 3 rutas, index lista jugadores, store sube+redimensiona+audita,
  store rechaza un archivo que no es imagen, destroy borra+audita).
  Verificado en un clone descartable del VPS: 10/10 tests nuevos en verde,
  suite completa 188/190, mismos 2 fallos preexistentes sin relación.
  **Backup de la base (`php artisan backup:run`) tomado antes de correr la
  migración en producción** — cambio de esquema real, mismo criterio que
  cualquier otra migración de esta sesión. Migración corrida, migración de
  harek verificada con `curl` contra `/ranking` real (el ícono aparece al
  lado de su medalla de plata), módulo admin verificado end-to-end vía los
  tests del controller (ejercitan el mismo código que el form real) más
  confirmación de que la ruta está protegida y registrada
  (`route:list`, redirect 302 sin sesión).

## Bug real y urgente: ícono roto para el primer jugador real que lo probó (2026-08-28)

Mismo día del módulo de arriba — el dueño reportó "el ícono que le subí a
GenuineuPP. aparece como imagen tipo error" apenas lo probó de verdad.

**Causa raíz, encontrada en producción:** `storage/app/public/player-icons`
había quedado con dueño `root:root` (`755`) en vez de `www-data:www-data` —
porque el directorio se creó la primera vez que corrí `PlayerIcon::store()`
por SSH como `root` (migrando el burro de harek, entrada anterior), y ese
proceso "dueño" el directorio. La subida real de GenuineuPP. vino del form
web de verdad (Apache/PHP-FPM corriendo como `www-data`), que no tenía
permiso de escritura ahí — **exactamente la misma lección ya documentada en
"Servidores temporales self-service" sobre probar como `www-data`, no como
`root`**, y caí en la misma trampa en este módulo por no releerla antes de
armar el Task.

**Bug de código real, independiente del permiso, que agravó el síntoma:**
`Storage::disk('public')->put()` devuelve `false` en un fallo (permisos,
disco lleno) en vez de lanzar una excepción — `PlayerIcon::store()` ignoraba
ese valor de retorno y actualizaba `icon_path` en la fila de todas formas.
Resultado: la base decía "este jugador tiene ícono" apuntando a un archivo
que nunca se creó — ícono roto visible en el sitio, sin ningún error para el
admin que lo subió (ni un 500, ni un mensaje — el form devolvía "éxito").
Solo `dtN.harek` tenía un ícono real funcionando; `GenuineuPP.` era la
**primera subida real por el form web**, así que fue la primera en topar con
el bug.

- **Fix de infraestructura:** `chown -R www-data:www-data
  storage/app/public/player-icons` en el VPS — confirmado con
  `sudo -u www-data touch` que ya puede escribir ahí.
- **Fix de código en `PlayerIcon::store()`:** se saca el `self::destroy($player)`
  redundante que corría ANTES de escribir el archivo nuevo (el path es
  siempre `{player_id}.png`, nombre fijo — `put()` ya pisa el archivo
  anterior directo, no hacía falta borrar nada antes) y ahora se revisa el
  valor de retorno de `put()`: si es `false`, lanza `RuntimeException` y
  **nunca llega a tocar `icon_path`** — sin este orden (escribir primero,
  solo actualizar la columna si se confirma), un fallo de escritura en un
  reemplazo de ícono existente hubiera sido peor: el viejo se borraba, el
  nuevo fallaba, y el jugador se quedaba sin ninguno de los dos.
- **`Admin\PlayerIconController::store()`** atrapa esa excepción y la
  convierte en un error de formulario (`withErrors`) en vez de un 500 crudo
  o un "éxito" falso — la vista ya tenía el bloque `@if($errors->any())`
  desde el principio, solo faltaba que el controller lo alimentara en este
  camino.
- **El registro roto de GenuineuPP. se limpió a mano** (`icon_path` puesto
  en `null` vía tinker) — no había forma de recuperar el archivo que nunca
  llegó a crearse, así que la única opción real era que el ícono roto
  desapareciera del sitio y el dueño lo vuelva a subir (ahora sí va a
  funcionar, con el permiso corregido).
- TDD: 2 casos nuevos de regresión — `PlayerIconTest::test_store_throws_and_does_not_touch_the_database_if_the_disk_write_fails`
  (mockea `Storage::disk('public')->put()` para que devuelva `false`,
  confirma la excepción y que `icon_path` nunca se toca) y
  `PlayerIconControllerTest::test_store_shows_a_form_error_instead_of_a_broken_icon_when_the_disk_write_fails`
  (mismo mock a nivel de controller, confirma error de sesión en vez de
  éxito falso, y que no queda una `AdminAction` de un upload que en
  realidad falló). Verificado junto a la suite completa en un clone
  descartable del VPS: 190/192 tests, mismos 2 fallos preexistentes sin
  relación. Desplegado a producción, verificado con `sudo -u www-data
  touch` (permiso de escritura confirmado) y `curl` contra `/ranking`
  (el ícono roto de GenuineuPP. ya no aparece).

## Ícono de jugador visible en todo el sitio, no solo el top 3 (2026-08-28)

Seguimiento directo de la entrada anterior — el dueño le subió el ícono a
GenuineuPP. (puesto #6 del ranking) y no se veía en ningún lado. No era un
bug: el sistema original solo mostraba el ícono al lado de la medalla del
top 3 (mismo alcance que tenía el burro hardcodeado de harek que reemplazó).
Preguntado explícitamente el alcance deseado, eligió la opción más amplia:
mostrarlo siempre, en cualquier lugar del sitio donde aparece el nombre de
un jugador.

- **`<x-player-icon :player="$player" />`** (componente Blade nuevo,
  `resources/views/components/player-icon.blade.php`) — reemplaza el bloque
  condicional que antes vivía duplicado dentro de `@if($i < 3)` en
  leaderboard/dashboard. Silencioso (`$player?->icon_url`) si el jugador es
  null o no tiene ícono, para poder usarlo sin envolver cada llamado en su
  propio `@if`.
- Insertado en **16 vistas públicas** en los ~25 lugares donde aparece el
  nombre de un jugador: `leaderboard.blade.php` (tabla general + paneles
  Axis/Allies), `dashboard.blade.php` (top jugadores), `players/show.blade.php`
  (header del propio perfil), `matches/show.blade.php` (leaderboard general/
  Axis/Allies, badges de más headshots/granadas/bash, grid de kills por
  ronda —atacante y víctima—, las 3 secciones de chat), `demos/show.blade.php`,
  `partials/live-status.blade.php` (jugadores conectados en vivo),
  `partials/team-balance.blade.php` (sugerencia de equipos), y las páginas
  de especialidades `countries`, `map-kings`, `rango`, `ranking` (plantilla
  compartida por ~10 páginas — podio + tabla), `rivalries` (verdugo y
  presa), `streaks`, `weapons`, `win-rate`. **Fuera de alcance a propósito:**
  paneles solo-admin (`/adm_cod2/jugadores/*`, consola admin) y los
  popovers renderizados por JS desde JSON (`/kills/{guid}`, el popover de
  "quién mató con esta arma" en `/armas`) — mostrar el nombre coloreado ya
  funciona ahí, agregar el ícono hubiera requerido tocar además el JSON de
  esos endpoints y el JS que arma cada fila, marginal para el valor que
  aporta.
- **Gotcha real encontrado al verificar en producción, no en el código
  nuevo:** `SpecialtyController::countries()` (`/paises`) seguía sin
  mostrar el ícono después del cambio — trae los jugadores con una lista
  explícita de columnas (`Player::...->get(['id', 'guid', 'last_name', ...])`)
  que nunca incluyó `icon_path`, así que el modelo nunca tenía el dato en
  memoria aunque la fila de la base sí lo tuviera. Único lugar de todo el
  código con este patrón (confirmado con un grep de cualquier
  `Player::...->get([...])`/`->select([...])` con columnas explícitas) —
  el resto de las páginas ya usaban `Player::...->get()` sin restricción
  (`KillAggregator`, los controllers de especialidades, las relaciones
  Eloquent `belongsTo`), así que no hacía falta tocar nada más ahí. Se
  agregó `icon_path` a esa lista.
- **Cuidado al insertar el componente dentro de contenedores `flex`:**
  en `specialties/countries.blade.php` los nombres viven sueltos dentro de
  un `<div class="flex flex-wrap">` (cada `<a>` es su propio ítem flex) —
  poner `<x-player-icon>` como hermano del `<a>` en vez de anidado adentro
  lo hubiera vuelto un ítem flex propio, pudiendo saltar de línea por
  separado del nombre al que pertenece. Se anidó dentro del `<a>` en ese
  caso puntual (y en los podios de `specialties/ranking.blade.php`, que
  usan `truncate` y necesitan el ícono dentro del mismo elemento para
  participar del recorte de texto correctamente) — en el resto de los
  casos (dentro de una `<td>` de tabla) da igual adentro o afuera del
  `<a>`, no hay riesgo de salto de línea independiente.
- TDD: `tests/Feature/PlayerIconDisplayTest.php` (2 casos — el ícono
  aparece en el ranking general aunque el jugador esté en el puesto #6, no
  solo top 3; la query exacta de `countries()` con sus columnas explícitas
  incluye `icon_path`) + `tests/Feature/Support/PlayerIconComponentTest.php`
  (3 casos — nada se renderiza sin ícono, nada se renderiza con jugador
  null, el `<img>` aparece con la URL correcta cuando sí hay ícono). El
  caso de `/paises` se probó contra la query directamente, no vía HTTP
  contra la ruta real — esa página depende de la base GeoIP real
  (`storage/app/geoip/country.mmdb`, no versionada en el repo, ver
  "GeoIP y banderas de país" más arriba), así que en un clone descartable
  sin ese archivo `GeoIp::countryFor()` siempre da null y el jugador ni
  aparece en la página, sin importar si el fix es correcto — mismo motivo
  exacto por el que `CountriesSeasonTest` ya es una de las 2 fallas
  preexistentes conocidas de la suite. Verificado junto a la suite
  completa en un clone descartable del VPS: 195/197 tests, mismos 2
  fallos preexistentes sin relación. Desplegado a producción, verificado
  con `curl` contra `/ranking`, `/paises`, `/jugadores/{guid}` y
  `/partidas/{id}` reales: el ícono de GenuineuPP. (puesto #6) aparece en
  los cuatro.

## Ambiente de testing (`testing.4livepro.com`) — dado de baja (2026-08-28 → 2026-08-29)

**Ya no existe.** A pedido del dueño (2026-08-29, "elimina el testing ya no lo
voy a usar") se borró todo: vhost de Apache (`.conf`/`-le-ssl.conf`),
certificado TLS (`certbot delete`), directorio `/var/www/testing.4livepro.com`
y la base `cod2_stats_test`. Producción (`cod2.4livepro.com`) no se tocó,
verificado con `curl` antes y después.

**El registro DNS sigue existiendo** — lo creó el dueño directamente en
Cloudflare, no se puede borrar desde este repo/VPS (sin API token de
Cloudflare). El primer intento de limpieza dejó un efecto secundario: sin
un vhost propio, una visita HTTPS a `testing.4livepro.com` caía al primer
vhost SSL que Apache tenía cargado alfabéticamente
(`cod2.4livepro.com-le-ssl.conf`, ya que `cod2...` ordena antes que
cualquier vhost por defecto de Ubuntu) y mostraba el sitio real en vez de
dar error o un placeholder — mismo tipo de fallback "vhost por defecto" ya
documentado para `monitor.4livepro.com` en "Subida automática de demos por
HWID" más arriba. El dueño lo notó y pidió que se corrigiera del todo.

**Fix (2026-08-29): `000-default-ssl.conf`** — se habilitó un vhost
`<VirtualHost *:443>` catch-all nuevo (`/etc/apache2/sites-available/
000-default-ssl.conf`, con el cert autofirmado `ssl-cert-snakeoil` que ya
trae Ubuntu, `DocumentRoot /var/www/html`), con el prefijo `000-` a
propósito para que ordene ANTES que `cod2.4livepro.com-le-ssl.conf` en
`sites-enabled/` — Apache usa el primer `<VirtualHost *:443>` cargado como
default para cualquier SNI/Host que no matchee ningún `ServerName`/
`ServerAlias` exacto, sin importar si hay o no un registro DNS apuntando
ahí. Mismo patrón que `000-default.conf` (el catch-all de puerto 80 que
Ubuntu ya trae de fábrica), solo que para 443 no existe uno por defecto —
hay que crearlo a mano. Con esto, `testing.4livepro.com` (y cualquier otro
hostname futuro que alguien apunte a esta IP sin crear un vhost real)
muestra la página default de Apache ("It works") en vez de filtrar el
sitio real. Verificado que `cod2.4livepro.com` y su alias real
`direct.cod2.4livepro.com` siguen respondiendo con el sitio normal — el
catch-all solo afecta a hostnames que no matchean ningún `ServerName`
existente. **No versionado en git** (vive en `/etc/apache2/` del VPS,
mismo precedente que el resto de esta sección) — si el VPS se reconstruye,
recrear este archivo (contenido completo más arriba en el historial de
git de este mismo archivo) y correr `a2ensite 000-default-ssl.conf`.

**Lo que sí sobrevive** (no se tocó, porque no es "el ambiente de testing" en
sí — son las exploraciones de diseño que se probaron ahí): las ramas git
`frontend-neon-redesign`, `frontend-framer-redesign` y
`frontend-hostgamer-redesign` (ver "Exploración de rediseño de frontend" más
abajo) y el directorio `testing frontend/` con los `.md` de referencia.
Si se quiere volver a levantar un ambiente de pruebas para seguir iterando
sobre alguna de esas ramas, repetir el procedimiento completo descripto
originalmente más abajo en esta misma sección (vhost, cert, DB, `.env` con
el mismo `APP_KEY` que producción).

<details>
<summary>Contenido original de esta sección, para no perder el procedimiento si se vuelve a necesitar</summary>

A pedido del dueño, para poder probar cambios sin tocar `cod2.4livepro.com` en
vivo. Vivía en el mismo VPS (`151.245.32.43`) que producción, como un segundo
vhost de Apache completamente separado en filesystem y base de datos — pero
**compartía el mismo gameserver real y los mismos permisos de sistema que
producción**, ver el aviso de riesgo más abajo, era la parte más importante de
esta sección.

### Qué se armó

- **DNS**: `testing.4livepro.com` — registro creado por el dueño en Cloudflare,
  proxiado (nube naranja) igual que el dominio principal, apunta al mismo VPS.
- **Filesystem**: `/var/www/testing.4livepro.com/`, poblado con
  `git archive HEAD | ssh ... tar -x` (mismo mecanismo que `deploy.sh`, apuntado
  a mano al VPS nuevo — ver "`deploy.sh` apunta al VPS viejo" más arriba).
  - `vendor/` copiado tal cual desde producción (`cp -a`, 46M) en vez de correr
    `composer install` — mismo `composer.lock` exacto (mismo commit), así que
    el resultado es idéntico y se ahorra la descarga/resolución de paquetes.
  - `storage/` armado desde cero (`framework/{cache,sessions,views}`, `logs`,
    `app/public`, `app/private`), **sin copiar los demos reales**
    (`storage/app/private/demos`, 772M en producción al momento de crear
    esto — no aportan nada para probar código y el VPS solo tenía 5.2G
    libres). Sí se copiaron `storage/app/public/maps` (imágenes de mapa,
    versionadas en git de todos modos) y `storage/app/geoip/country.mmdb`
    (no versionado, pero chico y hace falta para que `/paises` y las
    banderas funcionen igual que en producción).
- **`.env`**: copiado del real y editado solo en `APP_URL`, `APP_ENV=testing`
  y `DB_DATABASE=cod2_stats_test` — **el `APP_KEY` es el mismo que
  producción a propósito**, porque la copia de la tabla `servers` trae
  `rcon_password` ya encriptado con ese `APP_KEY` (cast `encrypted` de
  Laravel, ver "Multi-servidor" más arriba); con un `APP_KEY` distinto esa
  columna no se puede desencriptar y el RCON del panel de testing rompe.
- **Base de datos**: `cod2_stats_test`, mismo usuario (`cod2_stats`) con
  `GRANT ALL` sobre la base nueva. Poblada con un `mysqldump` completo de
  `cod2_stats` de producción al momento de crear esto (2026-08-28) —
  **es una foto fija, no se sincroniza después**. Si se necesita una copia
  más nueva más adelante, repetir
  `mysqldump --single-transaction cod2_stats | mysql cod2_stats_test`.
- **Apache**: `/etc/apache2/sites-available/testing.4livepro.com.conf` (mismo
  patrón que `cod2.4livepro.com.conf`, `DocumentRoot` propio), certificado TLS
  propio vía `certbot --apache -d testing.4livepro.com` (no expandido sobre el
  cert de `cod2.4livepro.com` — dominio raíz distinto, tiene sentido un cert
  separado; vence 2026-11-27, renovación automática ya registrada por
  certbot). Reusa el mismo pool de PHP-FPM que producción (la config
  `proxy-sendcl`/socket de `php8.3-fpm.conf` es global, no por vhost) — no se
  creó un pool aparte, el VPS ya anda ajustado de RAM (ver "Prioridad del
  gameserver..." más abajo) y un segundo pool solo suma workers ociosos.

**Nada de esto (vhost, cert, la base `cod2_stats_test`) es parte del repo git**
— mismo precedente que el resto de infraestructura de "Migración a VPS
dedicado". Si el VPS se reconstruye, hay que rehacer todo esto a mano con los
comandos de arriba.

### ⚠️ El RCON y el control de systemd apuntan al servidor REAL — decisión explícita del dueño

La tabla `servers` copiada trae las credenciales RCON reales de Pug Latam, y
`www-data` tiene los mismos permisos de `sudoers` (`/etc/sudoers.d/cod2-panel`)
sin importar desde qué vhost se invoquen. Esto significa que **el panel de
admin de `testing.4livepro.com/adm_cod2` controla el mismo gameserver en vivo
que `cod2.4livepro.com`** — kick, ban, cambio de mapa, mensaje, y reiniciar/
parar/iniciar `cod2server.service` afectan a jugadores reales ahora mismo, no
hay ningún servidor "de mentira" para probar esas acciones sin consecuencias.
Se preguntó explícitamente al dueño antes de armar esto (opción "apuntar al
server real" elegida a sabiendas del riesgo, sobre la alternativa de dejar la
tabla `servers` vacía/con RCON inválido). Lo mismo aplica a servidores
temporales self-service (`hosted_servers`, mismo mecanismo de sudoers) si la
copia de la base tenía alguna fila activa al momento del dump.

**Lo único que SÍ está aislado es el código (deploy independiente) y los
datos de jugadores/partidas/rankings (base de datos separada)** — un cambio
de código o una partida "de prueba" en `cod2_stats_test` no toca
`cod2_stats` real, pero cualquier botón del panel de admin que hable con el
gameserver por RCON o `systemctl` sí es real.

### Qué NO se activó (a propósito)

- **Ningún cron/scheduler para testing** (`cod2:parse-log`,
  `demos:reconcile-matches`, `cod2:recalculate-stats`, etc.) — el VPS tiene 1
  solo core y RAM ajustada (ver "Migración a VPS dedicado" más abajo); correr
  el parser dos veces sobre el mismo `games_mp.log` en paralelo (una vez para
  `cod2_stats`, otra para `cod2_stats_test`) no aporta nada útil para un
  ambiente de pruebas manual y sí compite por CPU con el server real. La base
  de testing queda estática (la foto del dump) hasta que alguien la
  refresque a mano. Si en algún momento hace falta que también parsee en
  vivo, agregar una entrada de cron aparte apuntando explícitamente al
  `artisan` de `/var/www/testing.4livepro.com`.
- **Ningún despliegue automático a testing** — un `deploy.sh`/`git archive`
  futuro a producción no toca `testing.4livepro.com` para nada (son
  directorios y vhosts completamente separados); si se quiere usar este
  ambiente como "staging" antes de cada deploy real, hay que desplegar ahí
  a mano con el mismo comando (`git archive HEAD | ssh ... tar -x -C
  /var/www/testing.4livepro.com`) — no hay ningún pipeline armado para eso.

</details>

## Exploración de rediseño de frontend (2026-08-29)

El dueño pidió cambiar el frontend (motivo del ambiente de testing de arriba)
y probó tres direcciones visuales completas, cada una en su propia rama git
(nunca en `main`), desplegada de a una sobre `testing.4livepro.com` mientras
existió:

1. **`frontend-neon-redesign`** — tema "Void terminal glass" de neon.com
   (`testing frontend/neon-db-design.md`), fondo negro + acento menta
   `#34D59A`, esquinas planas. **Rechazado por el dueño** ("no me gustó").
2. **`frontend-framer-redesign`** — tema "Void-canvas agent noir" de
   framer.com (`testing frontend/framer-design.md`), acento azul eléctrico
   `#0099FF`, esquinas moderadamente redondeadas (8-20px), tipografía Plus
   Jakarta Sans + Inter. Refinado con la skill `ui-ux-pro-max` (ver abajo).
   Sin veredicto explícito del dueño todavía cuando se armó la tercera opción.
3. **`frontend-hostgamer-redesign`** — inspirado en hostgamer.net a pedido
   directo del dueño ("hazlo tipo este diseño"). A diferencia de las otras
   dos (mood-boards reconstruidos a mano en un `.md`), estos valores salen
   del **CSS real** del sitio (se bajó su bundle de Next.js y se leyeron sus
   variables `--color-*` directamente) — fondo `#090911`, panel `#111420`,
   acento `#2258FF`, tipografías Manrope + Oxanium, radios 6-16px, más un
   glow tipo neón en los botones de acento sólidos.

En las tres se usó el mismo mecanismo de alto apalancamiento: remapear los
tokens `slate`/`cyan` de Tailwind (y `panel`/`panel2`/`gsprimary`/`gsaccent`)
en el `tailwind.config` inline de `layouts/app.blade.php` y
`layouts/admin.blade.php`, en vez de editar clase por clase las ~49
plantillas — la mayoría ya usaba esos dos tokens de sobra (>1000 usos de
`slate-*`, 263 de `cyan-*`) para fondo/texto/bordes/acento, así que
redefinir la escala ahí cascadea el look nuevo solo.

**Lección repetida en las tres iteraciones — cuidado si se retoma esto:** los
tonos de gris literales de un mood-board/sitio de referencia (`border`/
`text-muted`) casi siempre están pensados para **bordes decorativos**, pero
en este código real `slate-400/500/600` se usan mayoritariamente como
**texto de verdad** (headers de tabla, helper text, labels — 400+ apariciones
combinadas). Aplicar el gris "muted" literal del sitio de referencia ahí
rompe el contraste WCAG AA (4.5:1) contra los paneles oscuros del sitio. Las
tres iteraciones necesitaron un ramp de grises recalculado a mano (verificado
por luminancia relativa, no solo "se ve parecido") para que 400/500/600
sigan siendo legibles, reservando los tonos más oscuros del sitio de
referencia para `slate-700`/`800` (ahí sí son casi solo bordes, sin texto).

**Ninguna de las tres se mergeó a `main` ni se desplegó a producción** — las
ramas siguen existiendo en el repo (locales y/o en GitHub según si se
pushearon) como punto de partida si el dueño elige retomar alguna. El
ambiente de testing donde se probaron ya no existe (ver arriba) — para
volver a ver cualquiera de las tres en vivo hay que rearmar el ambiente y
desplegar la rama correspondiente.

## Webhook de Discord con resultado de partida (2026-08-31)

`cod2:notify-discord-matches` (cron cada minuto, `routes/console.php`, mismo
ritmo que `cod2:parse-log`) postea a un webhook de Discord el resultado de
cada partida SD real que llegó a un resultado desde la última corrida —
mapa, marcador (con 🔴/🔵 según ganó axis/allies, rojo/azul también en el
color del embed), duración, servidor, y los líderes de esa partida (🏆 MVP,
🎯 Headshots, 💣 Granadas, todos vía `KillAggregator::aggregate()` sobre los
kills de esa partida). Formato del embed reescrito el 2026-09-01, ver esa
fecha más abajo.

- **`settings.discord_match_webhook_url`** (nullable, editable desde
  `/adm_cod2/discord` junto al resto de la config de Discord ya existente) —
  mientras esté vacío, el comando se sale sin hacer nada (`blank(...)`
  check al principio de `handle()`). La URL se genera desde Discord mismo
  (Configuración del canal → Integraciones → Webhooks), no hay forma de
  crearla desde este repo.
- **`matches.discord_notified_at`** (nullable timestamp) — se marca apenas
  se postea con éxito, para no volver a notificar la misma partida en la
  corrida siguiente. Un `POST` fallido (Discord caído, URL inválida) NO
  marca la partida — reintenta en la próxima corrida automáticamente.
- **Mismo criterio de "partida real" que `/partidas`**:
  `GameMatch::scopeVisibleInListing()` (ya excluye partidas
  fantasma/abandonadas, ver la bitácora de bugs más arriba) + `gametype=sd`
  (el pug prioriza Search & Destroy) + `is_backfilled=false` (el historial
  importado no tiene fecha real).
- `app/Services/DiscordMatchNotifier.php` — construye el embed y hace el
  `POST`; usa `TeamSideAnalyzer::winningSideForMatch()` (la versión liviana
  ya usada por `/partidas` para el badge Axis/Allies del listado, no
  `sideScores()`, que pide construir el leaderboard completo).
- **Bug real encontrado en el propio test de esta feature, no en el código
  de producción:** el fixture (`NotifyDiscordMatchesTest::realMatch()`)
  colgaba el evento `match_end` de la PRIMERA ronda creada
  (`$match->rounds()->first()`) en vez de la última —
  `TeamSideAnalyzer::clusterRoundWinners()` recorta las rondas a
  `id <= round_id del match_end`, así que con el evento en la ronda 1 solo
  quedaba 1 ronda visible (`final_score` necesita mínimo 2) y el marcador
  desaparecía del embed en el test. Sin relación con el bug real de
  producción (ronda 386 de una partida real, ver la bitácora de bugs) —
  acá era simplemente el fixture de prueba mal armado.
- **Segundo bug real, de infraestructura, no de lógica:** la migración que
  agrega `discord_match_webhook_url` pasó los tests (SQLite en el entorno
  aislado ignora `->after()` silenciosamente) pero falló contra el MySQL
  real de producción — apuntaba a `->after('hosted_servers_max_concurrent')`,
  una columna renombrada a `hosted_servers_ports` el 2026-08-25 (ver
  "Servidores temporales self-service" más arriba). Falló limpio antes de
  tocar la tabla (confirmado con `information_schema.COLUMNS` antes de
  reintentar) — corregido y redesplegado sin dejar la base en un estado
  intermedio. **Lección:** una migración con `->after('columna_vieja')`
  puede pasar la suite igual si el motor de test no valida FKs/orden de
  columnas como MySQL sí lo hace — no asumir que "pasó en el clon aislado"
  cubre este tipo de error, tenerlo en cuenta si se repite el patrón.
- TDD: `tests/Feature/NotifyDiscordMatchesTest.php` (7 casos — sin webhook
  configurado no postea nada; postea una partida real y la marca; no la
  postea dos veces; no postea partidas que no son SD; no postea el
  histórico importado; no postea una partida abandonada sin resultado
  real; una respuesta fallida de Discord no marca la partida). Verificado
  junto a la suite completa en el clone descartable del VPS: 251/259
  tests, mismos 8 fallos preexistentes sin relación (ExampleTest,
  LocaleSwitcherTest ×3, MatchRoundDetailsTest, CountriesSeasonTest,
  ExtrasSeasonTest, RivalriesGrenadesTest — mismo baseline ya confirmado
  durante el despliegue de Usuarios y roles, el mismo día). Desplegado a
  producción con `php artisan migrate --force`, verificado con `curl`
  (sitio `200`) y `schedule:list` (el comando nuevo aparece registrado
  cada minuto). **Pendiente: el dueño todavía tiene que cargar la URL del
  webhook desde `/adm_cod2/discord`** — sin eso el comando sigue
  saliendo sin hacer nada, tal como se lo dejó verificado.

### Bug real: notificaba partidas antes de tiempo + rescate de DiscordTeamsNotifier (2026-09-01)

El dueño reportó una notificación real de Toujane con **"Duración: 0 min"**
— y por separado mostró una captura de una partida de Burgundy con un
formato de embed mucho más completo (Marcador con 🔴/🔵, Servidor, 🎯
Headshots, 💣 Granadas, imagen grande, pie con ícono) que dijo era "como
debió quedar" — ninguno de los dos coincidía con lo que este archivo
documentaba.

**Causa raíz del "0 min", confirmada contra la base real:**
`cod2:notify-discord-matches` usaba `GameMatch::scopeVisibleInListing()`
—pensado para `/partidas`, que también debe mostrar partidas **en curso**
con un badge— en vez de exigir que la partida ya hubiera terminado de
verdad. `matches.discord_notified_at` de la partida 125 (Toujane) quedó en
**2 segundos después** de `started_at`: se notificó apenas arrancó, sin
rondas jugadas todavía.

**Segundo hallazgo, más grave, mismo diagnóstico:** al revisar
`discord_notified_at` de todas las partidas, **61 partidas históricas**
(desde el 2026-08-09) quedaron marcadas como notificadas en una ráfaga de
apenas 2 minutos (16:17:03 → 16:19:04) — la primera vez que alguien cargó
una URL de webhook, el comando encontró todas las partidas reales viejas
con `discord_notified_at` aún `null` (la columna recién se había agregado)
y las mandó todas de una. Esos 61 mensajes ya se enviaron, no hay forma de
recuperarlos/borrarlos del canal desde acá.

**Fix, `app/Models/GameMatch.php`:** nuevo `scopeReadyToNotify()` —
`reachedConclusion()` (13+ rondas o evento `match_end`, ya existía) **Y**
la partida ya no es el `current_match_id` del parser para su server (mismo
puntero que ya usa `scopeAbandonedWithoutConclusion()`). Hace falta esto
además de `reachedConclusion()` porque una partida que va a **overtime**
(empate 12-12) puede tener 13+ rondas reales y seguir jugándose — el
conteo de rondas solo no alcanza como señal de "esto terminó". Más el
corte de seguridad `where('ended_at', '>=', now()->subHours(2))` en el
comando, para que ningún futuro "apagar y prender" el webhook (o cargar
uno nuevo) pueda volver a inundar el canal con partidas viejas.

**Sobre el formato "rico" de la captura — investigado, no inventado:**
encontrado `app/Services/DiscordTeamsNotifier.php` **viviendo solo en el
VPS, nunca comiteado a git** (mismo patrón exacto que el incidente de
`deploy.sh` de esta misma sesión, ver "Alguien puede desplegar desde otra
máquina..." más abajo) — junto con su test, su migración
(`discord_teams_webhook_url`), y `public/logo_cod2_icon.png`, los cuatro
con fecha de modificación **17:41:47 del 2026-08-31**, la misma hora exacta
en que el `CLAUDE.md` del VPS se editó con la falsa afirmación de que
`deploy.sh` ya estaba arreglado. Todo apunta a una sesión que trabajó
directo por SSH en el VPS esa hora, sin pasar nunca por este repo. Ese
`DiscordTeamsNotifier` es una feature DISTINTA (anuncia los equipos armados
en `/equipos`, no resultados de partida) — pero probablemente esa misma
sesión también construyó (o dejó andando) una versión más rica de
`DiscordMatchNotifier.php`, con el formato exacto de la captura del dueño.
**Esa versión no se pudo recuperar** — no quedó ningún rastro en el
filesystem del VPS (`find` sin resultados, sin `.git` propio ahí) — se
perdió cuando un `git archive HEAD | tar -x` posterior de esta sesión
pisó el archivo con la versión más simple que sí estaba en git.

**Se reconstruyó `DiscordMatchNotifier.php` a mano para igualar la
captura**, no adivinado: campos `Marcador` (con `🔴`/`🔵` según quién
ganó, mismo par de emoji que ya usa `DiscordTeamsNotifier` para Axis/
Allies), `Duración`, `Servidor` (`$match->server->name`), `🏆 MVP`, `🎯
Headshots`, `💣 Granadas` (los tres vía la misma `KillAggregator::aggregate()`
que ya se usaba para el MVP, ordenada por `kills`/`headshots`/
`grenade_kills` respectivamente — sin queries nuevas), `image` en vez de
`thumbnail` (imagen grande del mapa, no la miniatura chica de antes), y
`footer` con ícono (`asset('logo_cod2_icon.png')`) + `"Search & Destroy ·
{$server->name}"` en vez de `description` — mismo patrón de footer que
`DiscordTeamsNotifier` ya usaba.

**`DiscordTeamsNotifier` se rescató completo a git** (servicio + test +
migración + el logo), igual que se hizo con `deploy.sh` — agregado
`discord_teams_webhook_url` a `Setting::$fillable` y un campo nuevo en
`/adm_cod2/discord` para poder cargar esa URL desde el panel (antes solo
se podía setear por SQL/tinker directo, nunca desde la UI). En su momento
se dejó registrado que el botón "Notificar Discord" que el docblock de la
clase daba por hecho que existía nunca se había construido — **completado
el mismo día** (2026-09-01), ver la entrada siguiente.

### Botón "Notificar Discord" del balanceo de equipos (2026-09-01)

Seguimiento directo de la entrada anterior. `ConsoleController::notifyTeams()`
(nuevo, `POST /adm_cod2/console/{server}/notify-teams`, módulo `servers`)
recalcula el balance con RCON **en vivo en el momento del click**
(`Cod2RconClient::status()` + `TeamBalancer::suggest()` + `PlayerRankCalculator`)
en vez de confiar en el HTML ya renderizado — el roster conectado pudo
haber cambiado entre que se cargó la página y que se apretó el botón. Si
sale bien, llama a `DiscordTeamsNotifier::notify($server, $teamBalance->teamA, $teamBalance->teamB)`
(el mismo servicio rescatado, sin tocar) y audita vía `AdminAction::record('console.notify-teams', ...)`.

**El botón solo aparece en la consola admin, nunca en la página pública
`/equipos`** — `partials/team-balance.blade.php` (compartido entre las
dos) ahora acepta `$showDiscordButton` (default `false`), que solo
`admin/console.blade.php` pasa en `true`. Postear al canal de Discord es
una acción administrativa con efecto visible para toda la comunidad — no
tiene sentido que cualquier visitante anónimo de `/equipos` pueda
dispararla.

TDD: `tests/Feature/Admin/ConsoleNotifyTeamsTest.php` (2 casos — guest
redirigido a login, un admin sin el módulo `servers` recibe 403). Sin más
cobertura automatizada a propósito: `ConsoleController::notifyTeams()`
toca RCON real (`fsockopen` UDP) igual que el resto de
`ConsoleController` (`kick`/`ban`/`message`/`map`/`command`/`service`),
que deliberadamente no tiene tests de feature por el mismo motivo (cero
tests existentes para esos otros endpoints, mismo criterio ya establecido
en este proyecto) — se verifica en vivo contra el server real en vez de
mockear un socket UDP. Verificado junto a la suite completa en el clone
descartable del VPS: 281/289 tests, mismos 8 fallos preexistentes sin
relación. Desplegado a producción, ruta confirmada con `route:list`.

Se reseteó a mano `discord_notified_at = NULL` de la partida 125 (Toujane,
la única de las dos recién afectadas que realmente terminó — 21 rondas +
`match_end`, confirmado con `reachedConclusion()`/`stillCurrent()` antes de
tocarla) para que el próximo cron la renotifique con el formato correcto;
no así la 124 (Burgundy, solo 1 ronda, nunca fue una partida real — no
merece notificación de ningún tipo).

TDD: `tests/Feature/NotifyDiscordMatchesTest.php` ganó 5 casos (headshots/
granadas se muestran cuando la baja decisiva los tiene; no postea una
partida que recién arrancó con 0 rondas; no postea una partida con 13+
rondas mientras el parser la sigue rastreando como `current_match_id`
—el caso de overtime—; no inunda partidas históricas viejas aunque
`discord_notified_at` sea `null`) sobre las 6 que ya existían (una
reescrita para verificar el campo `Servidor` nuevo). `tests/Feature/DiscordTeamsNotifierTest.php`
(5 casos, ya escritos por la sesión que lo dejó en el VPS) necesitó un
fix de fixture al traerlo — su `server()` creaba un server nuevo con slug
`pug-latam`, que choca con el que la migración semilla ya crea en cada
`RefreshDatabase` (mismo gotcha ya documentado en
`UpdateHostedServerPortsTest::realServer()`) — corregido a `Server::first()`.
Verificado junto a la suite completa en el clone descartable del VPS:
279/287 tests, mismos 8 fallos preexistentes sin relación. Desplegado a
producción (`./deploy.sh --migrate` — la migración de `discord_teams_webhook_url`
ya estaba aplicada desde el trabajo directo en el VPS, `migrate` no tuvo
nada que hacer), verificado en vivo: `cod2:notify-discord-matches` corrido
a mano confirma que la partida 125 quedó renotificada con el formato
nuevo.

## sitemap.xml (2026-08-31)

`/sitemap.xml` (`SitemapController`, `resources/views/sitemap.blade.php`) —
para que Google indexe el sitio sin depender de rastrear cada link a mano.
Un solo archivo sin paginación (dataset chico: ~70 partidas reales, ~40
jugadores con actividad real hoy, sobra margen antes de necesitar dividirlo
— el límite real de un sitemap es 50.000 URLs).

- **Páginas estáticas**: dashboard, ranking, rango, equipos, `/partidas`,
  `/demos`, las ~24 páginas de `/especialidades`, FAQ, descargas — lista fija
  en el controller, con `priority`/`changefreq` a mano según qué tan seguido
  cambia cada una.
- **Partidas**: mismo criterio que `/partidas` (`scopeVisibleInListing()` +
  `gametype=sd` + `is_backfilled=false`) — solo partidas SD reales que
  llegaron a un resultado, nada fantasma/abandonado/histórico-sin-fecha.
- **Jugadores**: `kills_total > 0 OR deaths_total > 0` — excluye las filas
  fantasma de guid corrupto (ver la bitácora de bugs más arriba, el mismo
  criterio que ya usa el borrado en masa del admin).
- **Excluido a propósito**: todo `/adm_cod2/*`, `/servidores/{id}/{token}`
  (la URL ES la credencial del dueño de ese server temporal — indexarla
  sería publicar el control de un server ajeno), `/idioma/{locale}`
  (acción, no contenido), `/descargas/archivos/*` (navegador de archivos).
- Cacheado 1 hora (`Cache::remember('sitemap.xml', ...)`) — un crawler no
  necesita datos al segundo, y evita que cada hit repita las mismas
  queries.
- **`robots.txt`** (`public/robots.txt`, ya existía permisivo) ganó una
  línea `Sitemap: https://cod2.4livepro.com/sitemap.xml` al final. El
  bloque grande de reglas por user-agent que aparece antes de eso al
  visitarlo en producción (`Content-Signal:`, bloqueo de `GPTBot`/
  `ClaudeBot`/etc) **no es parte de este repo** — Cloudflare lo inyecta a
  nivel de borde ("Managed content", activable/desactivable desde su
  dashboard) por encima del archivo real que sirve este VPS, mismo
  precedente que cualquier otra config a nivel Cloudflare (no
  administrable desde acá sin sus credenciales).
- TDD: `tests/Feature/SitemapControllerTest.php` (10 casos — XML con
  content-type correcto, incluye las páginas estáticas principales, nunca
  incluye rutas admin/servidores-temporales/cambio-de-idioma, incluye una
  partida SD real concluida, excluye backfilled/no-SD/abandonada, incluye
  un jugador con actividad real, excluye uno fantasma de 0 kills/deaths,
  la respuesta queda cacheada). Verificado junto a la suite completa en el
  clone descartable del VPS: 261/269 tests, mismos 8 fallos preexistentes
  sin relación (mismo baseline de siempre). Desplegado a producción sin
  migración — verificado con `curl`: `/sitemap.xml` responde `200` con
  `Content-Type: application/xml; charset=UTF-8` y las URLs esperadas,
  `/robots.txt` incluye la línea `Sitemap:`.

## Usuarios y roles del panel admin (2026-08-31, ampliado 2026-08-31)

Sistema de permisos por módulo para `/adm_cod2` — nunca se documentó en su
momento (queda registrado ahora, de una, junto con el fix del mismo día que
lo tocó).

- **`users.is_super_admin`** (bool) — acceso total a todo, sin excepción.
- **`users.permissions`** (json, array de claves de `User::MODULES`) — para
  cualquier admin que no sea super-admin, la lista exacta de módulos a los
  que tiene acceso. `User::hasModule($key)` es la única fuente de verdad:
  `true` siempre si `is_super_admin`, si no `in_array($key, $permissions)`.
- **Middleware `module:<clave>`** (`EnsureHasModule`) gatea cada grupo de
  rutas en `routes/web.php` — sin el módulo, 403 (ni el link aparece en el
  nav, `layouts/admin.blade.php`, cada `@if(auth()->user()->hasModule(...))`).
- **Middleware `super-admin`** (`EnsureIsSuperAdmin`) — reservado para
  `/adm_cod2/usuarios` (gestionar otros admins) y para lo que se agregue
  después que sea igual de sensible. **Nunca** es un módulo otorgable via
  `permissions` — mezclar los dos sistemas sería dejar que un usuario se
  auto-otorgue poderes de super-admin editando su propio JSON.
- Protecciones en `Admin\UserController`: no podés borrar tu propia cuenta
  logueada, no se puede sacarle super-admin (ni borrarlo) al último
  super-admin que queda — para no quedar nunca sin nadie con acceso total.

### Bug real: el módulo "servers" permitía editar/borrar el server real (2026-08-31)

El dueño creó un usuario (`zhaiks`) con el rol "servidores" para operar la
consola RCON del día a día, y notó que también podía editar/eliminar el
registro del server real (Pug Latam) — incluida su contraseña RCON — desde
`/adm_cod2/servers`. Reportado explícitamente como incorrecto: un módulo
pensado para operación diaria (kick, mensajes, cambio de mapa, reiniciar el
servicio) no debería poder tocar la configuración de producción del
gameserver.

**Causa:** el diseño original de "servers" (2026-08-31) bundleaba
`Route::resource('servers', ...)` completo (index/create/store/edit/
update/destroy) junto con la consola RCON bajo el mismo middleware
`module:servers` — no había forma de otorgar solo la parte operativa.

**Fix:** el módulo `servers` ahora es solo `index` (ver la lista) + las
rutas de `console.*` (RCON). `create`/`store`/`edit`/`update`/`destroy` de
`ServerController` se movieron a un grupo gateado por `super-admin` —
mismo criterio que `/adm_cod2/usuarios`, un cambio de esta sensibilidad
(credenciales de producción) no debe quedar detrás de un checkbox
otorgable por otro admin, solo el super-admin puede tocarlo. La vista
`admin/servers/index.blade.php` esconde "+ Agregar servidor" y los links
Editar/Eliminar de cada fila con `@if(auth()->user()->is_super_admin)`.

**De paso, "servidores temporales" (self-service) se separó a su propio
módulo `hosted-servers`**, a pedido explícito del dueño — antes vivía
embebido en la misma página `/adm_cod2/servers` y bajo el mismo checkbox
"servers" que la consola RCON del server real, pese a ser una
responsabilidad completamente distinta (el feature público de
`/servidores/crear`, no Pug Latam). Nuevo controller
`Admin\HostedServerSettingController`, nueva página propia
`/adm_cod2/servidores-temporales`, nuevo link de nav — el contenido (la
tarjeta de config de puertos) es el mismo que ya existía, solo se movió de
lugar. Ver `User::MODULES` para el detalle completo de cada módulo.

**Bug relacionado encontrado en el camino, mismo problema de fondo:** el
form de retención de demos (`demo_retention_days`, embebido dentro de
`/adm_cod2/demos`, `SettingController::update()`) posteaba a una ruta
gateada por `module:servers` en vez de `module:demos` — un admin con el
módulo "demos" pero sin "servers" no podía guardar ese form, aunque la
página entera de demos sí le cargaba. Movido al grupo correcto (`demos`).

**Segundo bug relacionado, más urgente:** `AuthController` redirigía tras
un login exitoso a `/adm_cod2/servers` (tanto `login()` como
`showLogin()` si ya había sesión) — con "servers" ahora un módulo
otorgable como cualquier otro, un admin sin ese módulo específico hubiera
recibido un **403 al primer segundo de loguearse**, antes de poder hacer
nada. Redirige a `admin.home` (el dashboard, sin gate de módulo) en su
lugar.

TDD: `tests/Feature/Admin/ServerModuleRestrictionsTest.php` (8 casos — un
usuario con solo "servers" puede ver la lista y la consola pero no puede
crear/editar/borrar un server ni tocar servidores temporales ni la
retención de demos; un super-admin sí puede todo; la vista esconde los
links de Editar/Eliminar para un no-super-admin). Reescritos
`ServerIndexShowsHostedServerPortsTest.php`/`UpdateHostedServerPortsTest.php`
para apuntar a la página/ruta nueva de servidores temporales en vez de la
vieja embebida. Verificado junto a la suite completa en el clone
descartable del VPS: 270/278 tests, mismos 8 fallos preexistentes sin
relación (baseline de siempre). Desplegado a producción sin migración —
`permissions` de `zhaiks` sigue siendo `["servers"]`, ahora con el alcance
correcto sin necesidad de tocar su fila.

## Login público con Discord + reclamo de perfil + biografía (2026-09-01)

Sub-proyecto 1 de 2 (el bot que mueva jugadores entre canales de voz de
Discord al generar equipos, en `/equipos`, queda para una sesión futura —
ver "Fuera de alcance" en el spec). Spec completa:
`docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md`.

Cualquier visitante puede iniciar sesión con su cuenta de Discord (botón
"Iniciar sesión con Discord" en el nav — solo aparece si
`DISCORD_CLIENT_ID` está cargado, mismo patrón de ocultamiento que ya usa
Turnstile), reclamar el perfil de jugador (`players`) que le corresponde
por HWID/guid, y cargar biografía, redes (Steam/Twitch/Instagram) y specs
de PC — todo visible en `/jugadores/{guid}`.

- **Guard y tabla completamente separados del panel admin.** Nueva tabla
  `site_users` + guard `site` (`config/auth.php`), sin ningún vínculo con
  la tabla `users`/guard `web` que usa `/adm_cod2`. `bootstrap/app.php`
  reemplazó el `redirectGuestsTo('/adm_cod2/login')` fijo por un closure
  que diferencia por path (`/adm_cod2/*` sigue yendo al login admin,
  cualquier otra ruta protegida por `auth:site` va a `/login`) — con eso
  se encontró y corrigió un bug real de paso: `is('adm_cod2/*')` no
  matcheaba la ruta raíz `/adm_cod2` (sin barra final), lo que hubiera
  roto el redirect de invitados del dashboard admin. Login vía
  `laravel/socialite` + `socialiteproviders/discord` (Discord no es un
  driver oficial de Socialite).
- **Reclamo por código de chat, sin aprobación manual.** Al tocar "¿Sos
  vos? Reclamá este perfil" en `/jugadores/{guid}`, se genera un código de
  8 caracteres válido 15 minutos (`SiteUser.pending_claim_player_id`/
  `claim_code`/`claim_code_expires_at`). El jugador lo escribe en el chat
  del servidor; `players:check-claims` (comando nuevo, cron cada minuto
  junto a `cod2:parse-log`) revisa `chat_messages` de los últimos 20
  minutos buscando ese código viniendo del `guid` real del jugador
  reclamado — si aparece, confirma (`SiteUser.player_id`, único a nivel de
  esquema, cardinalidad 1:1). Un código vencido nunca se confirma aunque
  aparezca tarde.
  - **Hallazgo real en revisión, corregido antes de desplegar:** dos
    cuentas distintas pueden legítimamente tener un reclamo *pendiente*
    sobre el mismo jugador todavía sin confirmar (el `store()` del reclamo
    solo bloquea contra un jugador ya *confirmado*, no contra otro
    pendiente) — si ambas llegaran a confirmarse en la misma corrida, la
    segunda `update()` chocaría contra el `unique` de `site_users.player_id`
    y tiraría una `QueryException` sin capturar, abortando el resto de la
    corrida del comando (los demás reclamos pendientes de ese minuto
    quedaban sin procesar, aunque se autocuraba al minuto siguiente).
    Corregido con el mismo patrón ya usado en
    `HostedServerPortAllocator`: `try/catch(UniqueConstraintViolationException){continue;}`
    alrededor de cada `update()` del loop, en vez de dejarlo propagar.
- **Perfil ampliado, solo una vez reclamado.** Desde `/mi-cuenta`: bio
  (texto plano, máx. 400 caracteres), Steam/Twitch/Instagram (validados
  contra `regex:/^https?:\/\//i` — sin esto, alguien podía guardar un
  esquema `javascript:` que el perfil público renderiza como `<a href>`
  real, un XSS almacenado real detectado en revisión y corregido antes de
  que la vista pública llegara a renderizar esos campos), y specs de PC en
  4 campos (CPU/GPU/RAM/Periféricos). El de Discord se muestra solo
  (viene del login, no se pide de nuevo). Editar solo funciona si
  `player_id` ya está seteado — `AccountController::update()` devuelve 403
  antes de tocar la base si no.
- **`PlayerMerger` traslada el vínculo al fusionar jugadores.** Caso real
  y frecuente en este proyecto (HWID que cambia entre sesiones, ver la
  bitácora de bugs más arriba) — sin este ajuste, fusionar un jugador con
  un reclamo confirmado lo perdía en silencio (la fila fuente se borra al
  final de `merge()`, el `nullOnDelete` de la FK no alcanza para
  *trasladar* el vínculo, solo para limpiarlo). Defensivo: nunca pisa un
  `site_user` que el destino ya tuviera.
- **Admin**: `/adm_cod2/jugadores/cuentas-discord` (módulo `players`,
  mismo lugar que fusionar/borrar/íconos — es la misma responsabilidad de
  "identidad de jugador") lista qué cuenta de Discord está vinculada a qué
  jugador, con botón para desvincular un reclamo equivocado — auditado
  con `AdminAction`.
- TDD en las 8 tareas de código (modelo, login, reclamo, confirmación,
  Mi cuenta, perfil público, `PlayerMerger`, admin) + las dos correcciones
  encontradas en revisión (bug del redirect admin, colisión de reclamos
  pendientes, validación de esquema de URL). Verificado en un clon
  descartable del VPS (`/root/sdd_login_discord_reclamo_perfil/`, mismo
  patrón de siempre) antes de cada commit: suite completa 313/321 tests,
  mismos 8 fallos preexistentes sin relación (`ExampleTest`,
  `LocaleSwitcherTest` ×3, `MatchRoundDetailsTest`, `CountriesSeasonTest`,
  `ExtrasSeasonTest`, `RivalriesGrenadesTest`).

**Pendiente antes de que esto funcione en producción:** el dueño tiene que
crear la aplicación en el Discord Developer Portal
(developer.discord.com/applications), configurar el redirect URI
(`https://cod2.4livepro.com/auth/discord/callback`) y cargar
`DISCORD_CLIENT_ID`/`DISCORD_CLIENT_SECRET`/`DISCORD_REDIRECT_URI` en el
`.env` del VPS — sin esto el botón de login queda oculto (no rompe el
resto del sitio).

**Deja lista la base para el sub-proyecto 2 (a futuro, bot de Discord +
botón "Mover a canales de voz" en `/equipos`):** cada `site_users` ya
guarda `discord_id` (el snowflake real de Discord), el dato exacto que un
bot con permiso "Mover miembros" necesita para saber a quién mover, una
vez que el jugador reclamó su perfil.

## Transición de rank_score en el arranque de temporada (diseño en curso, 2026-09-02)

**NO IMPLEMENTADO TODAVÍA — esto es un diseño en progreso (brainstorming
arquitectural), pausado a pedido del dueño antes de terminarlo.** Documentado
acá para no perder las decisiones ya tomadas si se retoma en otra sesión.

**Motivo:** el mecanismo de semilla de MMR ya existente
(`PlayerRankCalculator::seasonSeedScore()`, ver "Módulo de íconos..." — no, ver
la sección de PlayerRankCalculator más arriba en el historial de este archivo,
agregada 2026-09-02 junto al resto del overhaul de rango) usa un score binario:
o el jugador ya calificó esta temporada (M≥9 partidas, usa el score real de
percentiles) o cae de golpe al score semilla completo (100% percentil KD de
la temporada anterior cerrada) o a un neutro fijo de 50 si nunca jugó antes.
El dueño pidió que la transición sea gradual, partida a partida, en vez de un
salto de golpe al llegar a la partida 9.

**Fórmula pedida por el dueño, textual:**

- Si `M < 9` (no calificado todavía): `rank_score = (1 - M/9)×rank_score_semilla + (M/9)×Rank_Score_T2_Actual`
- Si `M ≥ 9` (calificado): `rank_score = 0.50×P_WR + 0.30×P_KD + 0.20×P_IMP` (sin cambios, la fórmula que ya existe)
- Jugador nuevo en la temporada (nunca jugó la anterior): `rank_score_semilla = 50` (neutro).

**Decisiones ya tomadas con el dueño, vía brainstorming (AskUserQuestion):**

1. **Sigue siendo 100% interno a Equipos/TeamBalancer, nunca público.**
   `/especialidades/rango` no cambia — sigue exigiendo M≥9 para aparecer, sin
   mostrar ningún rango S-D provisional durante la transición. Confirmado
   explícitamente ("No debe aparecer, solo cuando se complete las 9
   partidas").
2. **Pool de percentil para `Rank_Score_T2_Actual`:** se calculan los
   percentiles WR/KD/Impacto del jugador en transición insertando sus stats
   parciales (las que lleva con sus M partidas) dentro de la MISMA
   distribución de los jugadores YA calificados esta temporada (M≥9) — sin
   alterar el percentil de nadie que ya califica, sin armar un pool aparte
   combinado. Elegido explícitamente sobre la alternativa de un pool
   combinado (todos los participantes, calificados o no).
3. **Sin persistencia nueva.** `rank_score_semilla` se deriva en vivo cada vez
   (mismo patrón que el `seasonSeedScore` actual, memoizado solo durante el
   request) — no hace falta una columna/tabla nueva porque la temporada
   anterior ya está cerrada e inmutable. Elegido explícitamente sobre
   persistir un snapshot fijo al cerrar temporada.
4. **Arquitectura para evitar repetir el bug de performance recién arreglado**
   (ver el commit `9f56224`, "Equipos/Discord recalculaba toda la temporada
   anterior por cada jugador conectado"): nuevo método **batch**
   `PlayerRankCalculator::transitionScoresForServer(Server $server, array $guids): array`
   que calcula el pool de calificados de la temporada actual UNA sola vez
   (no una vez por jugador conectado) y devuelve `guid => rank_score` para
   todos los guids pedidos de una. `TeamBalancer::suggest()` lo va a llamar
   una sola vez antes de su loop, igual que ya recibe `$ranks` precalculado
   del caller — nunca dentro del loop por jugador. Elegido explícitamente
   sobre la alternativa de un método por-guid memoizado internamente (más
   parecido a la convención de `seasonSeedScore` actual, pero más fácil de
   volver a romper si alguien llama una ruta no memoizada en el futuro).

**Algoritmo de `transitionScoresForServer()` (diseñado, sección 2 del
brainstorming, presentada pero sin confirmar el último punto):**

1. `M` = partidas jugadas esta temporada por ese guid (reusa
   `PlayerRankCalculator::matchesPlayedByPlayer()`, ya existe).
2. `rank_score_semilla` = `seasonSeedScore($server, $guid) ?? 50.0`.
3. Si `M = 0` → `rank_score = semilla` directo, sin calcular
   `Rank_Score_T2_Actual` (no hace falta, evita trabajo de más).
4. Si `M ≥ 1` → ubica las stats parciales del jugador (KD/WR/Impacto reales
   con sus M partidas) dentro de la distribución del pool de calificados
   (calculado una sola vez para todos los guids pedidos) via percentil por
   **interpolación de posición** (no búsqueda exacta -- el valor del jugador
   en transición probablemente no está en la lista de calificados).
   `Rank_Score_T2_Actual = 0.50×P_WR + 0.30×P_KD + 0.20×P_IMP` con esos tres
   percentiles interpolados.
5. `rank_score = (1 - M/9)×semilla + (M/9)×Rank_Score_T2_Actual`.

**Caso borde presentado, todavía sin confirmar por el dueño (se retomó la
conversación acá):** si todavía nadie llegó a 9 partidas esta temporada (pool
de calificados vacío -- la situación real ahora mismo, Temporada 2 recién
arrancada), no hay distribución contra la cual ubicar a nadie →
`Rank_Score_T2_Actual` no se puede calcular → esos jugadores usarían
`rank_score = semilla` hasta que exista al menos 1 jugador calificado. Sin
confirmación explícita todavía de que este es el comportamiento deseado.

**Pendiente antes de implementar (retomar desde acá):**
- Confirmar el caso borde del pool vacío (arriba).
- Terminar de presentar el resto del diseño (`TeamBalancer::suggest()` en
  detalle, testing plan, archivos tocados).
- Escribir el spec formal en `docs/superpowers/specs/` y que el dueño lo
  revise (todavía no se escribió ningún archivo de spec).
- El dueño pidió explícitamente, al pedir este análisis, que al terminar se
  le presenten **dos opciones de implementación**: vía subagente
  (`subagent-driven-development`/`writing-plans`) o directamente en esta
  sesión (código inline, el patrón que prefiere normalmente -- ver memoria
  `feedback_inline_execution_no_subagents.md`). Todavía no se llegó a esa
  decisión.

## Pendientes / conocido-roto

- **Servidores temporales self-service — activo en producción desde 2026-08-22,
  `max_concurrent=2`.** El VPS tiene poco margen real de RAM/disco (ver sección
  "Servidores temporales self-service" más arriba) — si se nota degradación del
  server real o del sitio con instancias temporales activas, lo primero es bajar
  `max_concurrent` a 1 (`config/hosted_servers.php` / `.env`), no asumir que es otra
  cosa.
- **Cuenta MaxMind bloqueada (4 license keys fallidas).** GeoIP está activo con
  DB-IP en vez de MaxMind — ver sección "GeoIP y banderas de país" más arriba para
  el detalle completo y cómo volver a MaxMind si algún día se resuelve.
- **Tailwind vía CDN, no compilado.** Decisión deliberada para no depender de un build
  step de Vite/npm en cada deploy — razonable para un sitio de este tamaño, pero si
  crece mucho valdría la pena migrar al pipeline de assets estándar de Laravel.
- **Asistencias no se registran** (decisión explícita del dueño) — el log no las trae
  nativamente y no se intentó inferirlas con heurísticas de daño previo.
- **La fórmula exacta del "Score" en pantalla de zPAM para los team-kills sigue sin
  confirmarse.** Dos pruebas contra el marcador real dieron resultados distintos: con
  `sherlockgen` un team-kill con rifle pareció sumar normalmente al Score (+1, como
  cualquier baja). Con `Jao` en la partida de Carentan del 2026-08-09 (`match_id=11`,
  ronda con marcador 8:3), el log confirmó 8 bajas reales suyas (una de ellas un
  team-kill contra `ZHAIKS`, mismo equipo), pero el Score en pantalla mostraba 6 — un
  déficit de 2 que cuadraría si esa vez el team-kill restó 1 punto en vez de sumar 1
  (7 bajas válidas − 1 = 6), no si simplemente se excluyera del conteo (7) ni si
  contara normal (8). Las dos pruebas se contradicen; no se tocó la lógica de
  `kills_total` por esto — el sitio sigue mostrando el conteo real de bajas (no el
  Score interno de zPAM), con los team-kills marcados aparte en rojo `(-N)` y su
  detalle (compañero + arma) disponible al hacer click. Decisión explícita del dueño
  de dejarlo así en vez de intentar imitar la fórmula de zPAM sin confirmarla del
  todo — ver conversación del 2026-08-09/10.

- ~~Feature de subida de demos por HWID todavía no está comiteada a git~~ —
  **ya resuelto, no vale la pena releer esto.** Se hizo originalmente por SSH directo
  sobre el VPS (2026-08-19), pero el mismo día se trajo todo a este repo en el commit
  `43d50d1` ("Sincronizar cambios del VPS: demos por HWID, bans, auditoria, mapas
  combinados") junto con bans persistentes, auditoría de admin, y las variantes de
  mapa combinadas — las cuatro cosas están comiteadas y se despliegan normal vía
  `deploy.sh` desde entonces. Lo único que sigue sin versionar es lo que es
  inherentemente de sistema, no de código: el mod zPAM (`_record.gsc`, `server.cfg`,
  vive en `/root/zpam_test/` del VPS sin git) y `/etc/sudoers.d/cod2-panel` (la regla
  que le da a `www-data` permiso para reiniciar el servicio) — si el VPS se
  reconstruye desde cero, ambos hay que recrearlos a mano (ver sus secciones
  correspondientes más arriba para el contenido exacto).
