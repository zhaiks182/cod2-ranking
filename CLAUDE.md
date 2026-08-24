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

- `post_max_size`/`upload_max_filesize` en `25M` (default de PHP es `8M` — muy chico
  para demos reales de 10-12MB). El viejo ya lo tenía así en su `php.ini` de
  `mod_php`; se perdió al pasar a FPM porque son archivos `php.ini` distintos
  (`/etc/php/8.3/apache2/php.ini` vs `/etc/php/8.3/fpm/php.ini`) y no hay ninguna
  automatización que los mantenga sincronizados.
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

### `deploy.sh` apunta al VPS viejo — pendiente de actualizar

`SSH_HOST="iptvwatch"` en `deploy.sh` sigue apuntando al VPS compartido viejo. Correr
`./deploy.sh` hoy despliega al respaldo, no al VPS nuevo en producción
(`151.245.32.43`). No se cambió en esta sesión porque el alias que use `deploy.sh`
depende de qué máquina de desarrollo lo corra (cada una tiene su propio
`~/.ssh/config`) — decidir con el dueño si conviene un alias nuevo estandarizado
(ej. `cod2-vps`) que cada dev configure igual, o hardcodear `root@151.245.32.43`
directo en el script. Mientras tanto, cualquier deploy real hay que hacerlo a mano
apuntando al VPS nuevo.

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
