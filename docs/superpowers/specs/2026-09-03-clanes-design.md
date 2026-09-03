# Módulo de clanes — diseño

**Fecha:** 2026-09-03
**Estado:** aprobado por el dueño, pendiente de implementación.

## Motivo

El dueño pidió un módulo para que los usuarios (cuentas públicas por Discord,
`SiteUser`) puedan crear clanes y que otros jugadores se unan, guiándose por
una captura de una plataforma de ladders/torneos de referencia. Esa captura
trae mucho más de lo que este sitio maneja hoy (ladders desafiables con ELO
propio, torneos/premios, partidas "clan vs clan" con rival y marcador) —
decidido explícitamente con el dueño acotar el alcance a identidad +
membresía + estadísticas reales de los miembros, dejando ladders/torneos
fuera de esta versión.

**Reemplaza `site_users.clan_tag`** (campo de texto libre existente, sin
validación, que cada jugador llenaba a mano desde 2026-09-01) — con un clan
real, ese campo deja de tener sentido como fuente de verdad separada.

## Modelo de datos

### `clans`

- `id`, `name` (único), `tag` (único, corto), `description` (texto libre,
  opcional), `logo_path` (nullable), `founder_site_user_id` (FK a
  `site_users`), timestamps. `created_at` es la fecha de fundación real, sin
  campo editable aparte.

### `clan_members`

- `id`, `clan_id`, `site_user_id`, `role` (enum: `founder`/`manager`/`member`),
  `joined_at`. Constraint única en `site_user_id` — un jugador solo puede
  tener una fila activa a la vez (un clan por jugador, confirmado con el
  dueño). Al salir/ser expulsado, la fila se borra (no soft-delete — no hace
  falta historial de membresías pasadas para esta versión).

### `clan_invitations`

- `id`, `clan_id`, `site_user_id` (el jugador involucrado), `direction`
  (enum: `player_requested`/`manager_invited`), `status` (enum:
  `pending`/`accepted`/`rejected`/`cancelled`), `created_by_site_user_id`
  (quién la originó — el propio jugador si es solicitud, el manager/fundador
  si es invitación), timestamps. Cubre las dos direcciones (solicitud del
  jugador, invitación del clan) con una sola tabla — el `direction` decide
  quién puede aceptar/rechazar cada fila (una solicitud la resuelve el clan,
  una invitación la resuelve el jugador).

### `site_users`

- Se elimina la columna `clan_tag` (migración de baja, ya no se escribe ni se
  lee desde ningún lado).

## Requisito de perfil reclamado

Crear o unirse a un clan (aceptar una invitación, o que se apruebe una
solicitud) exige `site_users.player_id` no nulo — sin un jugador real
vinculado no hay estadísticas que aportar, y el objetivo explícito del
módulo es que las stats del clan sean reales. Verificado en cada acción
relevante del controller, no solo en la UI.

## Roles y permisos

- **Fundador** (único, `clans.founder_site_user_id`): ascender/degradar
  Managers, aprobar/rechazar solicitudes, invitar, expulsar miembros
  (cualquiera menos a sí mismo), editar el clan, transferir la fundación,
  disolver el clan.
- **Manager**: aprobar/rechazar solicitudes, invitar, expulsar Miembros
  (nunca a otro Manager ni al Fundador), editar nombre/tag/descripción/logo.
  No puede tocar roles ni disolver.
- **Miembro**: sin permisos de gestión.

## Unirse

Dos caminos, ambos resuelven en `clan_invitations`:

1. **Solicitud del jugador** (`direction=player_requested`): un jugador con
   perfil reclamado, sin clan actual, crea la fila `pending`. Un
   Manager/Fundador del clan la aprueba (crea `clan_members`, borra
   cualquier otra invitación pendiente del jugador) o la rechaza.
2. **Invitación del clan** (`direction=manager_invited`): un Manager/Fundador
   busca un jugador (por nombre/alias, mismo patrón de búsqueda que
   `/adm_cod2/jugadores/fusionar`) y crea la fila `pending`. El jugador
   invitado la acepta o rechaza desde `/mi-cuenta`.

Un jugador que ya pertenece a un clan no puede tener invitaciones/solicitudes
pendientes nuevas (debe salir de su clan actual primero) — validado server-side.

## Salir / disolver

- **Miembro o Manager**: sale directo, sin condiciones.
- **Fundador**: no puede salir sin transferir la fundación primero (elige a
  otro miembro del clan, típicamente un Manager, como nuevo fundador — el
  elegido pasa a `founder`, el saliente pasa a `member` y ahí sí puede
  salir). Si está solo en el clan, su única salida es Disolver.
- **Disolver** (solo Fundador): borra el clan, todas las filas de
  `clan_members` y `clan_invitations` asociadas. Doble confirmación en la UI
  (mismo patrón que "Borrar jugador" en el admin).

## Estadísticas del clan

Agregado real de **todos los miembros actuales** (kills, muertes, K/D
combinado, cantidad de partidas distintas jugadas por cualquier miembro, sin
duplicar si varios miembros compartieron la misma partida) — mismo selector
de temporada que ya usan `/ranking`/`/rango` (activa por defecto, o
`?season=all`/una temporada cerrada). No se filtra por "solo lo jugado siendo
miembro del clan" — los `kills` no tienen ningún vínculo con clan en la base
de datos (el log del juego no sabe de esto), así que es el mismo agregado que
ya se puede calcular hoy para cualquier jugador, sumado entre los miembros
actuales.

Implementación: reusa `GameMatch::forSeason()` y el mismo patrón de
`KillAggregator` ya existente — nunca una tabla de detalle nueva por clan.

## Páginas y rutas

- `GET /clanes` — listado público, buscable por nombre/tag.
- `GET /clanes/{clan:slug}` — detalle: logo, fundador, fecha de fundación,
  miembros con rol, estadísticas agregadas (con selector de temporada).
  Botones de Gestionar/Salir/Disolver visibles solo si el visitante logueado
  tiene el permiso correspondiente.
- `GET /clanes/crear` + `POST /clanes` — requiere sesión (`auth:site`) y
  perfil reclamado.
- Gestión (aprobar/rechazar solicitudes, invitar, promover/degradar,
  expulsar, editar, transferir, disolver) vive dentro de la misma página del
  clan, no en rutas separadas — mismo patrón que la imagen de referencia
  (los botones de gestión aparecen en la propia página del equipo).
- Invitaciones recibidas por el jugador: nueva sección en `/mi-cuenta`
  (aceptar/rechazar).

## Admin

`/adm_cod2/clanes` (nuevo, bajo Moderación, mismo patrón que "Borrar
jugador"/"Fusionar jugadores") — listado de todos los clanes con botón de
disolver forzado, para intervenir ante abuso (nombre ofensivo, etc.) sin
depender de que el fundador coopere. Auditado vía `AdminAction::record()`,
mismo patrón que el resto del panel.

## Fuera de alcance (explícito)

- Ladders desafiables con ELO propio.
- Torneos y tabla de premios.
- Partidas "clan vs clan" con rival/marcador (no existe ese concepto en el
  pipeline de logs de este sitio).
- Lista de links/highlights de YouTube en el perfil del clan.
- Historial de membresías pasadas (quién estuvo en el clan antes).

## Testing

TDD sobre: creación de clan (exige perfil reclamado, nombre/tag únicos),
flujo de solicitud (crear, aprobar, rechazar, no duplicar mientras hay una
pendiente), flujo de invitación (crear, aceptar, rechazar), permisos por rol
(un Miembro no puede aprobar/expulsar/editar; un Manager no puede disolver
ni tocar roles; solo el Fundador puede transferir/disolver), transferencia de
fundación (el nuevo fundador pasa a `founder`, el viejo a `member`), salida
del fundador bloqueada sin transferir primero, disolución borra clan +
membresías + invitaciones, agregación de estadísticas por temporada, y el
listado/disolución forzada del admin.
