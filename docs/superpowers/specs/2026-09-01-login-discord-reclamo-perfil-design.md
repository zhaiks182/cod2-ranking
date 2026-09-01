# Login con Discord + reclamo de perfil + biografía (2026-09-01)

## Contexto

El sitio no tiene ningún sistema de cuentas públicas — solo existe el login de
`/adm_cod2` (tabla `users`, guard `web`, por `username`, para administradores).
Los jugadores (`players`) se identifican por `guid` (hash del HWID, ver
"Identidad del jugador" en `CLAUDE.md`), sin ningún vínculo a una persona real
con la que el sitio pueda hablar.

Este es el **sub-proyecto 1 de 2** de un plan más grande: el dueño quiere que,
más adelante, un botón en `/equipos` mueva automáticamente a los jugadores
entre canales de voz de Discord según el balanceo de equipos generado. Eso
necesita un **bot de Discord** (token propio, permiso "Mover miembros", en el
servidor real de la comunidad) y, sobre todo, saber **qué guid de jugador
corresponde a qué usuario de Discord**. Ese vínculo es exactamente lo que este
sub-proyecto construye — el bot (sub-proyecto 2) queda fuera de alcance acá,
pero el modelo de datos de este sub-proyecto lo deja listo para usarse
directamente (`site_users.discord_id`).

## Objetivo

Cualquier visitante puede iniciar sesión con su cuenta de Discord, reclamar el
perfil de jugador (`players`) que le corresponde por HWID/guid demostrando que
lo controla en el juego, y completar una biografía corta, redes sociales
(Steam/Twitch/Instagram) y specs de PC — visibles en `/jugadores/{guid}`.

## Fuera de alcance (sub-proyecto 2, a futuro)

- Bot de Discord, permisos de mover miembros, mapeo de canales de voz
  Axis/Allies, botón en `/equipos`.
- Cualquier acción que el sitio tome sobre el servidor de Discord de la
  comunidad — este sub-proyecto solo *lee* la identidad de quien inicia
  sesión (OAuth), nunca actúa sobre Discord.

## Decisiones ya tomadas (ver conversación de brainstorming)

1. **Verificación del reclamo: código de chat en el juego**, detectado
   automáticamente contra la tabla `chat_messages` que el parser ya llena
   (`say;`). Sin aprobación manual del admin en el camino feliz.
2. **Login: solo Discord (OAuth2), sin usuario/contraseña propio.** Sin
   mailer, sin recuperación de contraseña que mantener.
3. **Tabla y guard separados del admin** (`site_users` / guard `site`) — cero
   riesgo de mezclar cuentas públicas con el sistema de roles de
   `/adm_cod2`.
4. **Biografía**: texto plano, sin Markdown/HTML, límite corto.
5. **Perfil ampliado**: Steam, Twitch, Instagram (el de Discord se muestra
   solo, ya viene del login) + specs de PC en campos estructurados (CPU, GPU,
   RAM, Periféricos).
6. **Cardinalidad 1:1** — una cuenta de Discord reclama como máximo un
   jugador; un jugador es reclamado por como máximo una cuenta.

## Arquitectura

### Autenticación

- **`laravel/socialite` + `socialiteproviders/discord`** (paquete nuevo —
  Discord no es un driver oficial de Socialite, se resuelve con el paquete
  estándar de la comunidad `socialiteproviders`). Se registra vía
  `Event::listen(SocialiteWasCalled::class, ...)` en
  `AppServiceProvider::boot()`.
- **`config/services.php`**: entrada `discord` con `client_id`/`client_secret`/
  `redirect`, leídos de `.env` (`DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`,
  `DISCORD_REDIRECT_URI`). **El dueño tiene que crear una aplicación en el
  Discord Developer Portal** (developer.discord.com/applications) y cargar
  esas credenciales en el VPS antes de que esto funcione en producción —
  mismo tipo de dependencia externa manual que ya existe con Turnstile o las
  license keys de MaxMind (documentado en `CLAUDE.md`, sección
  "Pendientes"). El botón "Iniciar sesión con Discord" del nav solo se
  muestra si `config('services.discord.client_id')` está cargado — mismo
  patrón que ya usa el widget de Turnstile cuando sus keys faltan.
- **`config/auth.php`**: nuevo guard `site` (driver `session`) + nuevo
  provider `site_users` (driver `eloquent`, modelo `SiteUser`). El guard
  `web`/tabla `users` del admin no se toca.
- **`bootstrap/app.php`**: `redirectGuestsTo()` pasa de un string fijo a un
  closure que distingue por path — `/adm_cod2/*` sigue yendo a
  `/adm_cod2/login`, cualquier otra ruta protegida por `auth:site` va a
  `/login` (la pantalla pública).
- **Rutas nuevas** (`routes/web.php`, grupo público, sin prefijo):
  - `GET /login` → redirige a Discord (`Socialite::driver('discord')->redirect()`)
  - `GET /auth/discord/callback` → resuelve el usuario de Socialite, hace
    `firstOrCreate`/`update` sobre `site_users` por `discord_id`
    (actualiza `discord_username`/`discord_avatar_url` en cada login, por si
    cambiaron), loguea con `Auth::guard('site')->login($siteUser)`.
  - `POST /logout` → `Auth::guard('site')->logout()`.
  - `GET /mi-cuenta` (middleware `auth:site`) → estado de la cuenta: sin
    reclamo / reclamo pendiente (código + instrucciones) / reclamado (form
    de edición de bio/redes/specs).
  - `POST /mi-cuenta` (middleware `auth:site`) → guarda bio/redes/specs
    (solo si ya está reclamado).
  - `POST /jugadores/{player}/reclamar` (middleware `auth:site`) → arranca
    un reclamo pendiente, redirige a `/mi-cuenta`.
  - `POST /mi-cuenta/reclamo/cancelar` (middleware `auth:site`) → cancela un
    reclamo pendiente antes de que expire, por si el jugador se equivocó de
    perfil.

### Modelo de datos

Migración nueva `create_site_users_table`:

| columna | tipo | notas |
|---|---|---|
| `id` | bigint PK | |
| `discord_id` | string, único | snowflake de Discord — el dato que sub-proyecto 2 va a necesitar |
| `discord_username` | string | cacheado del login, se refresca en cada login |
| `discord_avatar_url` | string nullable | idem |
| `player_id` | FK nullable a `players`, **único**, `nullOnDelete` | el reclamo confirmado |
| `pending_claim_player_id` | FK nullable a `players`, `nullOnDelete` | reclamo en curso, sin confirmar todavía |
| `claim_code` | string nullable | código que hay que escribir en el chat del juego |
| `claim_code_expires_at` | timestamp nullable | 15 minutos desde que se generó |
| `bio` | string(400) nullable | |
| `steam_url` | string nullable | |
| `twitch_url` | string nullable | |
| `instagram_url` | string nullable | |
| `pc_cpu` / `pc_gpu` / `pc_ram` / `pc_peripherals` | string nullable c/u | |
| `created_at`/`updated_at` | timestamps | |

`player_id` único (nullable, MySQL/MariaDB permiten múltiples `NULL` — mismo
patrón que `hosted_servers.port`) garantiza la cardinalidad 1:1 a nivel de
esquema, no solo por convención de código.

**Modelo `SiteUser`** (`Illuminate\Foundation\Auth\User` como `Authenticatable`,
igual que `User`): relación `player()` (`belongsTo`). **Modelo `Player`** gana
`siteUser()` (`hasOne`, inverso) — así `/jugadores/{guid}` puede mostrar
bio/redes/specs con un solo `with('siteUser')`, sin N+1.

### Reclamo de perfil — flujo completo

1. Usuario logueado (guard `site`) visita `/jugadores/{guid}` de un jugador
   sin `siteUser` y él mismo sin `player_id` propio todavía → ve el botón
   "¿Sos vos? Reclamá este perfil".
2. `POST /jugadores/{player}/reclamar`: genera `claim_code` (`Str::upper(Str::random(8))`,
   suficiente entropía para que dos códigos activos nunca choquen en la
   práctica), `claim_code_expires_at = now()->addMinutes(15)`, guarda
   `pending_claim_player_id`. Redirige a `/mi-cuenta`.
3. `/mi-cuenta` muestra el código con instrucciones ("escribí **CÓDIGO** en
   el chat del servidor dentro de los próximos 15 minutos") + cuenta
   regresiva. Si expiró, ofrece "Generar un código nuevo" (mismo endpoint,
   pisa el código anterior).
4. **`players:check-claims`** (comando nuevo, cron cada minuto junto a
   `cod2:parse-log`/`demos:reconcile-matches`): para cada `site_user` con un
   `pending_claim_player_id` y `claim_code_expires_at` todavía vigente,
   busca en `chat_messages` de los últimos 20 minutos (mismo margen que
   `DemoMatchResolver`, con holgura sobre los 15 de expiración) un mensaje
   con `guid` = el `guid` real del `pending_claim_player_id` y
   `message` que contenga el `claim_code` (`str_contains`, no exige match
   exacto de todo el mensaje — el jugador puede escribir "mi codigo es
   XXXXX" en el chat). Si aparece: `player_id = pending_claim_player_id`,
   limpia los campos de reclamo pendiente. Si el código venció sin
   aparecer, no hace nada (queda "expirado" a ojos de `/mi-cuenta`, el
   usuario puede pedir uno nuevo).
5. **Ya reclamado por otro**: si alguien intenta reclamar un jugador cuyo
   `player_id` ya está tomado por otra cuenta, `POST /jugadores/{player}/reclamar`
   rechaza con un mensaje ("Este perfil ya fue reclamado. Si es un error,
   contactá a un admin.") — no se pisa un reclamo confirmado ajeno.

### Perfil público (`/jugadores/{guid}`)

Si `$player->siteUser` existe: card nueva debajo del header con biografía
(si hay), redes sociales como íconos con link (Discord siempre, tomado de
`discord_username`; Steam/Twitch/Instagram solo si están cargados), y specs
de PC en una fila chica de etiquetas. Si no hay `siteUser`, no se muestra
nada nuevo — el perfil se ve exactamente igual que hoy.

### Admin

Página nueva `/adm_cod2/jugadores/cuentas-discord`
(`Admin\SiteUserController`, bajo el módulo existente `players` — mismo
lugar que fusionar/borrar/íconos, es la misma responsabilidad de
"identidad de jugador"). Lista cuentas de Discord con su jugador vinculado
(si tiene), botón "Desvincular" por fila para corregir un reclamo
equivocado — auditado con `AdminAction::record('site-users.unclaim', ...)`,
mismo patrón que el resto del panel.

### Integración con `PlayerMerger` (importante, no opcional)

`app/Support/PlayerMerger.php` ya mueve `kills`/`demos`/`bans`/
`chat_messages`/estadísticas de un jugador fuente a un jugador destino
cuando el dueño fusiona dos filas de `players` (caso real y frecuente en
este proyecto — HWID que cambia entre sesiones, ver la bitácora de bugs de
`CLAUDE.md`, entrada de "guid corrupto" y el módulo de fusión). Si el
jugador fuente de una fusión tiene un `site_user` con `player_id` apuntando
a él, ese vínculo **tiene que re-apuntar al destino**, si no la fusión
rompe silenciosamente el reclamo de una cuenta real (el `nullOnDelete` de
la FK no alcanza acá — la fila fuente se borra al final de `merge()`, así
que sin este ajuste el reclamo simplemente desaparece). Se agrega un paso
en `PlayerMerger::merge()`: antes de borrar los jugadores fuente,
`SiteUser::where('player_id', $sourceId)->update(['player_id' => $targetId])`
— solo si el destino no tiene ya su propio `site_user` (no debería poder
pasar dado el 1:1, pero se verifica igual, defensivo).

## Testing

TDD, mismo patrón del resto del proyecto (clon descartable del VPS antes de
desplegar):

- `tests/Feature/Auth/DiscordLoginTest.php` — callback crea `site_user`
  nuevo con los datos de Discord; un login posterior actualiza
  `discord_username`/avatar sin duplicar la fila (mismo `discord_id`).
- `tests/Feature/PlayerClaimTest.php` — reclamo genera código y expiración;
  reclamar un perfil ya reclamado por otro se rechaza; reclamar sin sesión
  redirige a login; cancelar un reclamo pendiente limpia los campos.
- `tests/Feature/CheckPlayerClaimsCommandTest.php` — un `chat_message` real
  con el código correcto y el guid correcto confirma el reclamo; un código
  correcto pero guid distinto no confirma nada; un reclamo ya vencido no se
  confirma aunque el código aparezca tarde; no reconfirma un reclamo ya
  confirmado.
- `tests/Feature/AccountControllerTest.php` — guardar bio/redes/specs solo
  funciona si el `site_user` ya tiene `player_id`; validación de longitud
  de bio.
- `tests/Feature/Support/PlayerMergerClaimTest.php` — fusionar un jugador
  con `site_user` vinculado mueve el vínculo al destino, no lo pierde.
- `tests/Feature/Admin/SiteUserControllerTest.php` — guest redirigido,
  admin sin módulo `players` recibe 403, desvincular limpia `player_id` y
  audita.

## Pendiente antes de desplegar a producción

- El dueño tiene que crear la aplicación de Discord (Developer Portal),
  configurar el redirect URI (`https://cod2.4livepro.com/auth/discord/callback`)
  y cargar `DISCORD_CLIENT_ID`/`DISCORD_CLIENT_SECRET` en el `.env` del VPS
  — sin esto el botón de login queda oculto (no rompe el resto del sitio).
