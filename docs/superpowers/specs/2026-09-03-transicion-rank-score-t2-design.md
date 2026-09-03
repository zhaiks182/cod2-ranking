# Transición de rank_score en el arranque de temporada — diseño

**Fecha:** 2026-09-03
**Estado:** aprobado por el dueño, pendiente de implementación.
**Alcance:** interno a Equipos/TeamBalancer únicamente. No toca `/especialidades/rango`,
que sigue exigiendo `M ≥ PlayerRankCalculator::MIN_MATCHES` (9) para aparecer, sin cambios.

## Motivo

El mecanismo de semilla de MMR ya existente (`PlayerRankCalculator::seasonSeedScore()`,
2026-09-02) usa un score binario: o el jugador ya calificó esta temporada (M≥9, usa el
score real de percentiles) o cae de golpe al score semilla completo (100% percentil KD
de la temporada anterior cerrada) o a un neutro fijo de 50 si nunca jugó antes. El dueño
pidió que la transición sea gradual, partida a partida, en vez de un salto de golpe al
llegar a la partida 9 — y que el sesgo de la semilla se disuelva lo antes posible en
datos reales de la temporada actual, pero solo cuando haya señal suficiente para
confiar en esos datos (ver "Piso de pool mínimo" abajo).

## Fórmula (dictada por el dueño)

- Si `M < 9` (no calificado todavía): `rank_score = (1 - M/9)×rank_score_semilla + (M/9)×Rank_Score_T2_Actual`
- Si `M ≥ 9` (calificado): `rank_score = 0.50×P_WR + 0.30×P_KD + 0.20×P_IMP` (sin cambios, la fórmula que ya existe en `calculateForServer()`)
- Jugador nuevo en la temporada (nunca jugó la anterior, o no calificó ahí, o no hay
  temporada anterior cerrada): `rank_score_semilla = 50` (neutro) — ya es el
  comportamiento de `seasonSeedScore()` hoy (devuelve `null`, y el caller decide el
  fallback a 50).

## Decisiones de diseño

### 1. Alcance: solo interno a Equipos, nunca público

`/especialidades/rango` no cambia. Confirmado explícitamente con el dueño: "No debe
aparecer, solo cuando se complete las 9 partidas". Mismo criterio que ya rige
`seasonSeedScore()`.

### 2. Pool de percentil: el de calificados, nunca combinado

Los percentiles WR/KD/Impacto de `Rank_Score_T2_Actual` se calculan insertando las
stats parciales del jugador en transición (las que lleva con sus M partidas) dentro de
la MISMA distribución de los jugadores que YA califican esta temporada (M≥9) —
**por interpolación de posición**, no búsqueda exacta, porque el valor del jugador en
transición probablemente no está en la lista de calificados. Nunca se altera el
percentil de ningún jugador ya calificado, y nunca se arma un pool aparte que mezcle
calificados y no calificados.

### 3. Piso de pool mínimo — `MIN_POOL_SIZE = 10`, fijo en código

**El hallazgo más importante de este diseño, planteado por el dueño durante la
revisión:** con un pool de calificados chico, el percentil interpolado es
estadísticamente ruidoso — con pool de tamaño 1, cualquier jugador en transición queda
o mejor (100) o peor (0) que esa única referencia, sin term medio posible; y con un
pool chico en general, la composición del pool cambia día a día a medida que más gente
cruza las 9 partidas, así que el `rank_score` de alguien en transición puede saltar de
una generación de equipos a la siguiente sin que haya jugado distinto, solo porque el
pool de referencia cambió.

**Mitigación:** mientras el pool de calificados (M≥9) tenga **menos de 10** jugadores,
TODOS los jugadores en transición (sin importar su M) usan `rank_score = semilla`
directo, sin calcular `Rank_Score_T2_Actual`. Recién cuando el pool llega a 10 o más
calificados, arranca la interpolación de percentiles para cualquiera con M≥1. Fijo en
código (constante), no configurable desde el panel — parámetro de ajuste fino que no
se espera tocar seguido.

### 4. Sin persistencia nueva

`rank_score_semilla` se deriva en vivo cada vez, igual que hoy (memoizado solo durante
el request vía el fix de performance del commit `9f56224`) — la temporada anterior ya
está cerrada e inmutable, así que no hace falta una columna/tabla nueva ni un snapshot
fijo al cerrar temporada.

### 5. Arquitectura batch, no memoización por-guid

Elegido explícitamente sobre un método `transitionScore($server, $guid)` memoizado
internamente (más parecido a la convención de `seasonSeedScore` actual) — un método
**batch** es estructuralmente imposible de volver a romper con el mismo bug de
performance recién arreglado (`9f56224`, "Equipos/Discord recalculaba toda la
temporada anterior por cada jugador conectado").

## Algoritmo: `PlayerRankCalculator::transitionScoresForServer()`

```php
public static function transitionScoresForServer(Server $server, array $guids): array
{
    // MIN_POOL_SIZE = 10, ver decisión #3.
    $qualified = self::calculateForServer($server); // pool T2 actual, UNA sola vez para todos los guids pedidos
    $poolSufficient = $qualified->count() >= self::MIN_POOL_SIZE;

    $matchIds = GameMatch::forSeason(Season::current()->id)->pluck('id');
    $matchesByPlayer = self::matchesPlayedByPlayer($server->id, $matchIds); // una sola query batcheada

    $result = [];
    foreach ($guids as $guid) {
        $semilla = self::seasonSeedScore($server, $guid) ?? 50.0;
        $playerId = /* resolver guid -> player_id conectado, vía Player::where('guid', $guid)->value('id') o similar */;
        $m = $matchesByPlayer[$playerId] ?? 0;

        if ($m === 0 || ! $poolSufficient) {
            $result[$guid] = round($semilla, 1);
            continue;
        }

        // Stats parciales reales del jugador esta temporada (sus M partidas),
        // mismo criterio SD que el resto del sistema.
        [$kd, $winPct, $impact] = /* calcular con KillAggregator/ImpactScoreCalculator, scopeado a este guid+temporada */;

        $pKd = self::interpolatePercentile($qualified->pluck('kd'), $kd);
        $pWinPct = self::interpolatePercentile($qualified->pluck('winPct'), $winPct);
        $pImpact = self::interpolatePercentile($qualified->pluck('impact'), $impact);

        $actual = $pWinPct * 0.5 + $pKd * 0.3 + $pImpact * 0.2;
        $result[$guid] = round((1 - $m / self::MIN_MATCHES) * $semilla + ($m / self::MIN_MATCHES) * $actual, 1);
    }

    return $result;
}
```

`interpolatePercentile(Collection $poolValues, float $value): float` es un método
nuevo, extraído/generalizado del closure `$percentiles` privado que ya existe dentro
de `calculateForServer()` — ese closure hoy solo ubica valores que YA están en el pool
(vía `$firstIndexOf`); el nuevo helper necesita ubicar un valor arbitrario que
probablemente no está en la lista, por posición interpolada entre los dos valores del
pool más cercanos.

## `TeamBalancer::suggest()`

Gana un cálculo previo al loop:

```php
$transitionScores = $server
    ? PlayerRankCalculator::transitionScoresForServer($server, $guidsSinRangoConectados)
    : [];
```

Dentro del loop, cada jugador conectado sin rango en `$ranks` usa
`$transitionScores[$guid] ?? self::DEFAULT_SCORE` (el `DEFAULT_SCORE=50` plano queda
solo como red de seguridad si `$server` es null — mismo caso que hoy, sin romper los
usos existentes de `suggest()` sin server). Sin cambios de firma pública.

## Testing

Nuevo `tests/Feature/Support/PlayerRankTransitionTest.php`:

1. M=0, jugador nuevo → `rank_score` = 50 exacto.
2. M=0, jugador que calificó en T1 → `rank_score` = su percentil KD de T1 exacto.
3. Pool insuficiente (<10 calificados) → `rank_score` = semilla aunque M=8.
4. Pool suficiente (≥10) + M≥1 → fórmula ponderada correcta contra un percentil
   conocido, construido a mano.
5. **Regresión de performance** (mismo patrón agregado en `9f56224` para
   `seasonSeedScore`): pedir N guids de una calcula el pool UNA sola vez, no N veces —
   aserción de cantidad de queries antes/después.

`TeamBalancerTest`: un caso nuevo o ajustado donde un jugador conectado con M<9 recibe
el score de transición en vez del `DEFAULT_SCORE` plano de hoy.

## Archivos tocados

- `app/Support/PlayerRankCalculator.php` — `MIN_POOL_SIZE`, `transitionScoresForServer()`, `interpolatePercentile()`.
- `app/Support/TeamBalancer.php` — `suggest()` reemplaza el fallback actual.
- `tests/Feature/Support/PlayerRankTransitionTest.php` (nuevo).
- `tests/Feature/TeamBalancerTest.php` (ajustado).
- `CLAUDE.md` — reemplazar la sección "Transición de rank_score en el arranque de
  temporada (diseño en curso, 2026-09-02)" por el resultado final una vez desplegado.

## Fuera de alcance

- Bot de Discord / mover jugadores de canal de voz al generar equipos (ver
  "Login público con Discord..." en `CLAUDE.md`, sub-proyecto 2 pendiente).
- Cualquier cambio a `/especialidades/rango` o a la fórmula de jugadores ya calificados.
