# Rebalanceo de equipos con mínimo de movimientos — diseño

**Fecha:** 2026-09-04
**Estado:** aprobado por el dueño, pendiente de implementación.

## Motivo

El modo "mantener asignaciones anteriores" (2026-09-04, ver `CLAUDE.md`)
reparte a cada jugador nuevo conectado al equipo con el total de score más
bajo en ese momento, sin tocar a nadie ya asignado. Eso alcanza casi
siempre, pero no cuando los jugadores nuevos tienen scores muy distintos
entre sí (o simplemente no hay forma de acomodarlos sin desbalancear) — el
dueño pidió una forma explícita de rebalancear en ese caso, moviendo la
**menor cantidad posible** de jugadores que ya estaban jugando (no solo
repartir a los nuevos), sin llegar a rearmar todo desde cero.

Ejemplo real que motivó esto: 5v5 con 300 vs 310 (balanceado), entran 2
jugadores nuevos con scores muy distintos — ni la mejor combinación de
dónde ubicarlos alcanza para mantener el balance sin tocar a nadie más.

## Alcance

- Botón nuevo, **separado** de "Generar equipos" y del candado 🔒/🔓
  "Mantener asignaciones anteriores" — este último no cambia de
  comportamiento (sigue solo repartiendo nuevos, nunca moviendo a los ya
  asignados).
- Sigue siendo **solo una sugerencia** — mismo principio que todo el
  módulo desde el principio (`TeamBalancer`, ver `CLAUDE.md`): nunca mueve
  a nadie de equipo por RCON, el cambio real lo hace el admin/los
  jugadores a mano.

## Umbral de balance

**`MAX_SCORE_DIFF = 20`** (diferencia máxima tolerable entre la suma de
score de los dos equipos), fijo en código — mismo criterio que
`MIN_POOL_SIZE` en la transición de rank_score: un número de ajuste fino
que no se espera tocar seguido. Se busca la combinación con **menos
movimientos** que ya logre una diferencia ≤ 20; entre combinaciones con la
misma cantidad mínima de movimientos, se usa la de mejor balance (menor
diferencia).

**Restricción dura, no negociable, heredada del comportamiento ya
existente:** el tamaño de los dos equipos debe quedar lo más parejo
posible (diferencia de a lo sumo 1 jugador) en TODA combinación
considerada — nunca se evalúa ni se propone una combinación con equipos
de tamaños muy distintos, sin importar qué tan buen balance de score
logre. Esto no es una decisión nueva, es el mismo criterio que ya cumple
el snake draft de `suggest()`.

## Algoritmo — `TeamBalancer::rebalance()`

Entrada: los conectados actuales (mismo formato que `suggest()`) + la
última asignación guardada (`previousAssignments()`, guid → A/B — los
jugadores que faltan ahí son "nuevos"). Salida: mismo shape que
`suggest()` (`teamA`/`teamB`/`scoreA`/`scoreB`/`enough`/`eligible`/`bots`)
más:
- **`moved`**: cada jugador en `teamA`/`teamB` gana un flag `->moved`
  (bool) — `true` si estaba asignado a un equipo distinto antes de este
  rebalanceo, `false` si es nuevo o no se movió.
- **`metThreshold`** (bool) — si la combinación elegida logró bajar de 20.
- **`diff`** (float) — la diferencia de score final, para mostrarla igual
  si no se llegó al umbral.

Búsqueda exhaustiva por cantidad de movimientos, de menor a mayor:

1. Separar a los conectados en **fijos** (tienen entrada en
   `previousAssignments`, con su equipo actual) y **nuevos** (no tienen).
2. Para `movesCount` = 0, 1, 2, ... hasta la cantidad total de fijos:
   - Generar todas las combinaciones de `movesCount` jugadores fijos a
     "voltear" (pasarlos al equipo contrario a donde estaban).
   - Para cada combinación, probar todas las formas de ubicar a los
     jugadores nuevos entre los dos equipos (fuerza bruta, son pocos en la
     práctica — 1 a 4 típico).
   - De TODAS las combinaciones resultantes (volteo + ubicación de
     nuevos) que respeten el tamaño parejo de equipos, quedarse con la de
     menor diferencia de score.
   - Si esa mejor diferencia de este nivel de `movesCount` ya es ≤ 20,
     devolver esa combinación acá mismo — no se sigue buscando con más
     movimientos.
3. Si se agotan todos los niveles de `movesCount` sin bajar de 20 (scores
   muy desparejos, no hay forma), devolver la mejor combinación
   encontrada en TODA la búsqueda (la de menor diferencia global), con
   `metThreshold = false`.
4. Sin nada guardado en `previousAssignments` (primera vez, nada que
   preservar): el único jugador "fijo" es ninguno, así que el resultado es
   directamente la mejor ubicación posible de todos como "nuevos" — sin
   necesidad de un caso aparte en el código, la búsqueda ya lo resuelve
   sola (0 fijos → un solo nivel de `movesCount=0` posible).

**Salvaguarda de performance:** para la cantidad real de gente conectada a
un pug (hasta ~16-20), esta búsqueda corre en milisegundos. Si algún día
el roster fuera mucho más grande, `TeamBalancer` corta la búsqueda de
`movesCount` en un tope defensivo (`MAX_SEARCH_MOVES`, ej. 10) en vez de
seguir subiendo sin límite — no se espera que esto se alcance nunca en la
práctica, es solo para no dejar la request colgada ante un caso extremo.

## UI

- Botón **"🔁 Rebalancear equipos"** en `partials/team-balance.blade.php`,
  junto al candado — visible siempre que `$teamBalance->enough` (sin nada
  guardado todavía, se comporta como un armado normal, ver punto 4 del
  algoritmo).
- Cada jugador que se movió gana un badge chico **"↔ cambió de equipo"**
  en su fila — así el admin sabe exactamente a quién avisarle en el juego,
  sin comparar a mano contra la sugerencia anterior.
- Si `metThreshold` es `false`, aviso chico junto al resultado ("no se
  pudo bajar de :diff puntos de diferencia moviendo menos gente") en vez
  de mostrar el resultado como si estuviera balanceado.
- El resultado de "Rebalancear" se guarda como la nueva asignación
  (`rememberAssignments()`, mecanismo ya existente) — la próxima vez que
  se use "mantener" o "rebalancear" de nuevo parte de este resultado.
- Ruta nueva por servidor, público (`/equipos`) y admin (consola), mismo
  patrón que `notifyDiscord`/`notifyTeams` — un botón que dispara un POST
  y recalcula en el momento (RCON en vivo), no confía en el HTML ya
  renderizado.

## Testing

TDD en `tests/Feature/TeamBalancerTest.php`:
- 0 movimientos alcanza cuando ubicar bien a los nuevos ya resuelve el
  desbalance (no se toca a nadie ya asignado).
- 1 movimiento cuando hace falta mover exactamente a uno — escenario
  construido a mano donde se sepa cuál jugador tiene que moverse.
- Nunca mueve más jugadores de los estrictamente necesarios para bajar
  del umbral (verificar que una combinación con más movimientos de los
  que hacían falta nunca se devuelve si una con menos ya alcanzaba).
- Respeta el umbral de 20 como corte — dos escenarios: diferencia final
  exactamente en el límite (19 pasa, 21 no).
- Si ningún movimiento posible baja de 20, devuelve la mejor combinación
  encontrada con `metThreshold = false` y el `diff` real.
- Sin nada guardado previamente en `previousAssignments`, se comporta
  igual que un armado normal (mismo resultado que `suggest()` sin modo
  preservar, dentro de lo que el algoritmo de búsqueda pueda coincidir —
  no necesariamente idéntico al snake draft jugador por jugador, pero con
  el mismo balance final o mejor).
- Nunca propone equipos de tamaños muy dispares (diferencia de a lo sumo
  1), sin importar el balance de score que lograría ignorando esa regla.
