# Galería multimedia: videos/imágenes por usuario, comentarios, likes y notificaciones (2026-09-02)

## Contexto

El sitio ya tiene cuentas públicas con login por Discord (`site_users`, guard
`site`, ver `docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md`)
usadas hoy para reclamar un perfil de jugador y editar bio/redes/specs de PC.
No hay ningún sistema de contenido subido por usuarios, ni de comentarios, ni
de notificaciones en el sitio.

El VPS de producción tiene el disco ajustado (~91% usado a la fecha, con un
incidente real de crisis de espacio documentado en `CLAUDE.md`, sección
"Crisis de disco..."). Video e imagen pesan mucho más que cualquier otro
archivo que el sitio maneja hoy (los demos de partida, lo más pesado hasta
ahora, rondan 10-28MB) — por eso el pedido original ya viene con una cuota por
usuario, configurable desde el admin.

## Objetivo

Un módulo de galería multimedia: cualquier usuario con sesión de Discord
iniciada puede subir videos e imágenes (sin necesidad de haber reclamado un
perfil de jugador), opcionalmente vinculados a una partida real del sitio.
Todo lo subido se ve en una galería pública (`/galeria`), con likes y
comentarios — el dueño del contenido recibe una notificación dentro del sitio
cuando le comentan. Cada usuario tiene una cuota de almacenamiento total
(default 100MB), editable desde `/adm_cod2/galeria`, donde un admin también
puede borrar cualquier archivo o comentario.

## Decisiones ya tomadas (ver conversación de brainstorming)

1. **Cuota por usuario, no por archivo** — 100MB acumulados entre todos sus
   archivos (default, editable desde admin). Protege el disco del VPS mejor
   que un tope solo por archivo individual.
2. **Cualquier usuario logueado puede subir** — no hace falta haber reclamado
   un perfil de jugador (`player_id`), a diferencia de editar bio/redes en
   `/mi-cuenta`.
3. **Galería general del sitio** (`/galeria`), no una sección dentro del
   perfil de cada jugador — mezcla todo lo subido por todos, más reciente
   primero.
4. **Vínculo opcional a una partida real** (`matches`) — el usuario puede
   elegir, al subir, "esto es de la partida X".
5. **Formatos: solo mp4/webm (video) y jpg/png/webp/gif (imagen)** — el VPS
   tiene 1 solo core, no hay margen para transcodificar video en el
   servidor. Se acepta lo que cualquier navegador ya reproduce nativo.
6. **Almacenamiento en disco público**, servido directo por Apache (ver
   "Arquitectura → Almacenamiento" abajo) — no un controller de streaming
   privado como Demos, porque este contenido es público por diseño.
7. **Auto-borrado**: cada usuario puede borrar sus propios archivos, lo que
   libera esos MB de su cuota. Un admin puede borrar cualquier archivo de
   cualquiera.
8. **Metadatos mínimos**: solo un título corto obligatorio, sin descripción
   larga — YAGNI.
9. **Comentarios de texto plano**, borrables por: el autor del comentario, el
   dueño del video/imagen (moderar su propia publicación), o un admin.
10. **Likes: solo "me gusta"** (sin dislike), toggle simple, uno por usuario
    por ítem.
11. **Notificaciones: solo por comentario nuevo**, no por like (señal
    liviana, no amerita notificación — mismo criterio que YouTube). Se
    entregan dentro del sitio (campanita en el nav), no por Discord — el
    sitio no tiene infraestructura de DMs de Discord (solo webhooks de un
    solo sentido a un canal), construir eso sería mucho más trabajo del que
    amerita este módulo.

## Fuera de alcance

- Dislike, reacciones más allá de un solo "me gusta".
- Notificación por like.
- Notificación por Discord (DM de un bot) — solo campanita dentro del sitio.
- Aprobación manual antes de publicar — lo subido aparece de inmediato en la
  galería; el admin modera borrando después, no aprobando antes.
- Miniaturas/thumbnails generadas en el servidor para video (necesitaría
  `ffmpeg`, no confirmado instalado, y procesar video en un VPS de 1 core es
  arriesgado) — un video se muestra en la grilla con un ícono genérico, no
  una miniatura del contenido.
- Redimensionar imágenes subidas (a diferencia de `PlayerIcon`/
  `SiteUserAvatar`, que sí re-escalan porque son avatares chicos) — la imagen
  es el contenido en sí, se guarda tal cual la subió el usuario, ya acotada
  por la cuota.
- Respuestas anidadas a comentarios (hilos) — lista plana, más reciente
  primero.
- Cualquier tipo de notificación que no sea "te comentaron" — el modelo
  queda genérico (`type` discriminador) por si a futuro se agrega otra, pero
  no se construye nada más ahora.

## Arquitectura

### Modelo de datos

**`gallery_items`** (migración nueva):

| columna | tipo | notas |
|---|---|---|
| `id` | bigint PK | |
| `site_user_id` | FK a `site_users`, `cascadeOnDelete` | dueño |
| `title` | string(120) | obligatorio |
| `type` | enum string `image`/`video` | derivado del mimetype al subir, no editable |
| `file_path` | string | relativo al disco `public`, ej. `gallery/{site_user_id}/{uuid}.mp4` |
| `mime_type` | string | |
| `size_bytes` | unsigned bigint | usado para calcular la cuota |
| `match_id` | FK nullable a `matches`, `nullOnDelete` | vínculo opcional |
| timestamps | | |

**`gallery_comments`**:

| columna | tipo | notas |
|---|---|---|
| `id` | bigint PK | |
| `gallery_item_id` | FK, `cascadeOnDelete` | |
| `site_user_id` | FK a `site_users`, `cascadeOnDelete` | autor |
| `body` | string(500) | texto plano |
| timestamps | | |

**`gallery_likes`**:

| columna | tipo | notas |
|---|---|---|
| `id` | bigint PK | |
| `gallery_item_id` | FK, `cascadeOnDelete` | |
| `site_user_id` | FK a `site_users`, `cascadeOnDelete` | |
| `created_at` | timestamp | sin `updated_at` (no se edita, solo se crea/borra) |

Único compuesto `(gallery_item_id, site_user_id)` — un like por usuario por
ítem, la unicidad la garantiza el propio esquema, no solo el código (mismo
criterio que `site_users.player_id` único en el módulo de reclamo).

**Notificaciones: se reusa el sistema nativo de Laravel, no una tabla
propia.** `SiteUser` ya usa el trait `Notifiable` (agregado con el login de
Discord, para dejar la puerta abierta a esto) — ese trait ya trae su propia
relación `notifications()`/`unreadNotifications()` sobre una tabla
`notifications` estándar (morph `notifiable_type`/`notifiable_id`, `type`,
`data` json, `read_at`). Diseñar una tabla y relación propias con ese mismo
nombre hubiera chocado con el trait — se usa lo que Laravel ya da:

- Migración `php artisan notifications:table` (la de Laravel, sin
  modificar).
- `App\Notifications\GalleryCommentPosted` (clase `Notification` nueva,
  implementa `toDatabase()` devolviendo `{gallery_item_id, gallery_item_title,
  comment_id, actor_site_user_id, actor_name}`) — se dispara con
  `$galleryItem->siteUser->notify(new GalleryCommentPosted($comment))`, sin
  cola (sin `ShouldQueue`, mismo criterio que el resto del sitio: sin
  infraestructura de queue workers para nada más que esto, se ejecuta
  sincrónico en el mismo request que crea el comentario — un solo insert,
  costo despreciable).
- Leer: `$siteUser->unreadNotifications` (built-in), marcar leído:
  `$siteUser->unreadNotifications->markAsRead()` (built-in) — nada de
  lógica propia para esto.

**Modelos**: `GalleryItem` (`belongsTo` `SiteUser`, `belongsTo` `GameMatch`
nullable, `hasMany` `GalleryComment`, `hasMany` `GalleryLike`), `GalleryComment`,
`GalleryLike`. `SiteUser` gana `galleryItems()` (`hasMany`).

### Cuota

`settings.gallery_quota_mb` (columna nueva en la tabla `settings` existente,
mismo patrón singleton que `demo_retention_days`/`hosted_servers_ports`),
default `100`. `Setting::galleryQuotaMb()` (accessor con fallback si es
`null`, mismo patrón que `Setting::maxConcurrent()`).

`GalleryQuota::remainingBytes(SiteUser $siteUser): int` (helper nuevo,
`app/Support/GalleryQuota.php`):
```php
$usedBytes = GalleryItem::where('site_user_id', $siteUser->id)->sum('size_bytes');
return (Setting::current()->galleryQuotaMb() * 1024 * 1024) - $usedBytes;
```
Usado por: la validación del formulario de subida (regla custom que rechaza
si `size_bytes del archivo nuevo > remainingBytes()`, con mensaje "Te quedan
X MB de tu cuota de Y MB") y por la propia página `/galeria/subir` para
mostrarle al usuario cuánto le queda antes de intentar.

### Almacenamiento

Disco `public` de Laravel (`storage/app/public/gallery/{site_user_id}/`,
accesible vía el symlink `public/storage` ya existente), servido **directo
por Apache** — no por un controller de streaming como Demos, porque acá el
contenido es público por diseño (a diferencia de Demos, que es privado a
propósito). Esto importa en particular para video: Apache sirve HTTP Range
requests nativo sobre un archivo estático, necesario para poder adelantar/
retroceder sin descargar el archivo entero — replicar eso a mano en un
controller PHP sería trabajo extra sin necesidad.

`GalleryUpload::store(SiteUser $siteUser, UploadedFile $file, string $title, ?int $matchId): GalleryItem`
(helper nuevo, `app/Support/GalleryUpload.php`) — valida cuota, determina
`type` por mimetype, nombra el archivo con un UUID (nunca el nombre original,
mismo motivo que demos: evitar colisiones y caracteres raros en el
filesystem), guarda con `Storage::disk('public')->putFileAs(...)`, crea la
fila.

`GalleryItem::delete()` — se sobreescribe (o se maneja en el controller) para
borrar también el archivo físico (`Storage::disk('public')->delete($this->file_path)`)
antes de borrar la fila, mismo patrón ya usado en
`Admin\MatchController::destroy()` para los demos de una partida.

### Infra: límite de subida de PHP

**Cambio de infraestructura necesario, fuera de este repo** (mismo tipo de
ajuste ya documentado en `CLAUDE.md`, "Bug real: demos grandes rechazados con
413"): `post_max_size`/`upload_max_filesize` en
`/etc/php/8.3/fpm/php.ini` están en `60M` — un archivo cercano a una cuota de
100MB rebotaría con 413 antes de llegar a Laravel. Hay que subirlos (ej. a
`110M`, con margen sobre el tope real) + `systemctl reload php8.3-fpm`. Se
aplica en el mismo despliegue que este módulo, documentado en `CLAUDE.md`
junto al resto de ajustes de infraestructura del VPS.

### Rutas y páginas públicas

Estilo de rutas: mismo patrón que el resto del sitio (`Route::...->middleware('auth:site')`
por ruta suelta, no un grupo — ver `routes/web.php` actual).

- `GET /galeria` (`GalleryController@index`, público, sin login) — grilla de
  `GalleryItem` paginada, más reciente primero. Filtro opcional `?tipo=video|imagen`.
  Cada tarjeta: imagen tal cual subida (con `loading="lazy"`) o ícono genérico
  de video + título, autor (con `<x-player-icon>`/nombre de Discord), contador
  de likes, contador de comentarios.
- `GET /galeria/{galleryItem}` (`GalleryController@show`, público) — el
  `<video controls>` o `<img>` a tamaño completo, título, autor, partida
  vinculada si tiene (link a `/partidas/{id}`), botón de like (deshabilitado/
  redirect a `/login` si no hay sesión) con contador, lista de comentarios +
  form para comentar (solo si hay sesión).
- `GET /galeria/subir` (middleware `auth:site`) — formulario: archivo, título,
  selector opcional de partida (mismo patrón de búsqueda que ya usa algún
  selector existente, o un `<select>` simple de las últimas N partidas reales
  del jugador si tiene perfil reclamado — a definir en el plan de
  implementación, no bloqueante para el diseño). Muestra cuánto le queda de
  cuota antes de subir.
- `POST /galeria` (middleware `auth:site`) — valida y crea.
- `POST /galeria/{galleryItem}/like` (middleware `auth:site`) — toggle
  (crea si no existe el like de este usuario para este ítem, borra si ya
  existía).
- `POST /galeria/{galleryItem}/comentarios` (middleware `auth:site`) — crea
  el comentario + la notificación para el dueño (si el autor del comentario
  no es el propio dueño — no te notifica un comentario tuyo en tu propio
  video).
- `DELETE /galeria/{galleryItem}` (middleware `auth:site`) — solo si
  `$galleryItem->site_user_id === Auth::guard('site')->id()`, si no 403.
- `DELETE /galeria/comentarios/{galleryComment}` (middleware `auth:site`) —
  autorizado si el usuario logueado es el autor del comentario **o** el
  dueño del `GalleryItem` al que pertenece; si no, 403 (un admin borra desde
  el panel, ver abajo).
- `GET /notificaciones` (middleware `auth:site`) — lista de
  `$siteUser->notifications` (todas, no solo las no leídas, para que el
  historial no desaparezca), más reciente primero, paginada; marca las no
  leídas al visitar la página (`$siteUser->unreadNotifications->markAsRead()`,
  método nativo del trait `Notifiable`).

### Campanita de notificaciones (nav)

En `layouts/app.blade.php`, junto a los otros íconos condicionales del nav
(el de servidor temporal activo, el selector de idioma) — visible solo si
`auth('site')->check()`. Muestra un badge con
`auth('site')->user()->unreadNotifications->count()` (relación nativa del
trait `Notifiable`, sin lógica propia) y linkea a `/notificaciones`.

### Admin (`/adm_cod2/galeria`)

Nuevo módulo `gallery` en `User::MODULES` (`app/Models/User.php`). Página
(`Admin\GalleryController`, mismo patrón que `/adm_cod2/demos`): campo de
cuota (`settings.gallery_quota_mb`) editable arriba, listado de todo lo
subido (usuario, título, tipo, tamaño, fecha, cantidad de comentarios) con
botón "Borrar" por fila (borra archivo + comentarios + likes vía
`cascadeOnDelete`) y un link "Ver comentarios" que abre un listado de los
comentarios de ese ítem con su propio botón de borrar individual. Todo
auditado con `AdminAction::record('gallery.destroy'|'gallery.comment-destroy'|'gallery.quota-update', ...)`,
mismo patrón que el resto del panel.

## Testing

TDD, mismo patrón del resto del proyecto (clon descartable del VPS antes de
desplegar):

- `tests/Feature/Support/GalleryQuotaTest.php` — cuota restante correcta con
  0/varios archivos; un archivo que excede la cuota se rechaza.
- `tests/Feature/GalleryUploadTest.php` — sube imagen/video válido; rechaza
  formato no permitido (ej. `.mov`); rechaza si excede la cuota; guarda
  `match_id` cuando se elige uno; requiere sesión.
- `tests/Feature/GalleryItemTest.php` — el dueño puede borrar su propio
  ítem (y el archivo físico desaparece, `Storage::fake`); otro usuario no
  puede (403); borrar libera la cuota (confirmado con una segunda subida
  que antes hubiera excedido y ahora entra).
- `tests/Feature/GalleryLikeTest.php` — togglear like crea/borra; el
  contador es correcto; requiere sesión.
- `tests/Feature/GalleryCommentTest.php` — crear comentario funciona con
  sesión, falla sin ella; comentar genera una notificación
  (`Notification::assertSentTo($dueño, GalleryCommentPosted::class)`) para
  el dueño **excepto** cuando el autor es el propio dueño (`assertNotSentTo`);
  borrar comentario: autor puede, dueño del ítem puede, un tercero no puede
  (403).
- `tests/Feature/NotificationsPageTest.php` — lista las notificaciones del
  usuario logueado, no las de otro; visitar la página marca las no leídas
  como leídas.
- `tests/Feature/Admin/GalleryControllerTest.php` — guest redirigido; admin
  sin módulo `gallery` recibe 403; actualizar la cuota se refleja en
  `GalleryQuota`; borrar un ítem/comentario desde el admin audita.
