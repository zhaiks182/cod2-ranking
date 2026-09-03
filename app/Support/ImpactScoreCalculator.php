<?php

namespace App\Support;

use App\Models\Kill;
use App\Models\PlayerMatchExtra;
use App\Models\Round;
use Illuminate\Support\Collection;

/**
 * "Impacto" (2026-09-02, a pedido del dueño) -- componente nuevo del score
 * de rango, para incentivar jugar a ganar la ronda en vez de acumular bajas
 * sueltas. Devuelve puntos crudos por player_id; PlayerRankCalculator
 * convierte esto a percentil como las demas metricas (kd/winPct).
 *
 * Puntaje:
 * - Bomba: plantar +1.0, desactivar +1.5 (player_match_extras, solo tiene
 *   datos desde 2026-08-27 en adelante, ver CLAUDE.md).
 * - Primera sangre de la ronda: +1.0 (primer kill real -- no suicidio ni
 *   fuego amigo -- en orden cronologico).
 * - Multi-kill en la misma ronda: 2=+1.0, 3=+2.0, 4=+3.5, 5+=+5.5. Solo el
 *   nivel mas alto alcanzado, no acumulativo (cuenta real de kills reales
 *   -sin suicidio/fuego amigo- del jugador en esa ronda).
 * - Clutch 1vX: el jugador quedo como UNICO sobreviviente de su equipo en
 *   algun momento de la ronda frente a X enemigos vivos, Y su equipo gano
 *   la ronda. X = enemigos vivos en el instante exacto en que murio el
 *   penultimo companero. 1v1=+1.5, 1v2=+2.5, 1v3=+4.0, 1v4+=+6.0. Mismo
 *   nivel "solo el mas alto", pero por definicion solo hay un X real por
 *   ronda (el instante en que el equipo queda en 1).
 *
 *   El roster ganador completo sale de rounds.winner_guids (siempre incluye
 *   a TODOS, no solo a quien sigue vivo al final -- ver el comentario de
 *   SpecialtyController::clutches()). El roster perdedor se aproxima con
 *   cualquier guid que aparezca en los kills de esa ronda y no este en el
 *   roster ganador -- mismo criterio de "participo" que ya usa el resto del
 *   proyecto (PlayerRankCalculator, KillAggregator), no hay una lista
 *   explicita de "quien estaba en el equipo perdedor" en ningun lado.
 */
class ImpactScoreCalculator
{
    /** @return array<int, float> player_id => puntos de impacto */
    public static function calculate(int $serverId, Collection $matchIds): array
    {
        $points = [];

        $extras = PlayerMatchExtra::whereHas('match', fn ($q) => $q->where('server_id', $serverId)->whereIn('id', $matchIds))
            ->get(['player_id', 'bomb_plants', 'bomb_defuses']);
        foreach ($extras as $extra) {
            $points[$extra->player_id] = ($points[$extra->player_id] ?? 0) + $extra->bomb_plants * 1.0 + $extra->bomb_defuses * 1.5;
        }

        $rounds = Round::where('server_id', $serverId)->where('gametype', 'sd')
            ->whereIn('match_id', $matchIds)
            ->get(['id', 'winner_guids']);

        $kills = Kill::whereIn('round_id', $rounds->pluck('id'))
            ->orderBy('occurred_at')
            ->get(['round_id', 'occurred_at', 'attacker_player_id', 'attacker_guid', 'victim_player_id', 'victim_guid', 'is_suicide', 'is_teamkill']);

        // guid -> player_id valido en este rango de partidas, para resolver al
        // sobreviviente de un clutch aunque no haya tenido ninguna kill/muerte
        // propia en esa ronda puntual (mismo patron que PlayerRankCalculator).
        $guidToPlayerId = [];
        foreach ($kills as $kill) {
            if ($kill->attacker_player_id) {
                $guidToPlayerId[$kill->attacker_guid] = $kill->attacker_player_id;
            }
            if ($kill->victim_player_id) {
                $guidToPlayerId[$kill->victim_guid] = $kill->victim_player_id;
            }
        }

        $killsByRound = $kills->groupBy('round_id');

        foreach ($rounds as $round) {
            $roundKills = $killsByRound->get($round->id, collect());
            if ($roundKills->isEmpty()) {
                continue;
            }

            $realKills = $roundKills->filter(fn ($k) => ! $k->is_suicide && ! $k->is_teamkill);

            $first = $realKills->first();
            if ($first && $first->attacker_player_id) {
                $points[$first->attacker_player_id] = ($points[$first->attacker_player_id] ?? 0) + 1.0;
            }

            foreach ($realKills->groupBy('attacker_player_id') as $playerId => $killsForPlayer) {
                if (! $playerId) {
                    continue;
                }
                $bonus = self::multiKillBonus($killsForPlayer->count());
                if ($bonus > 0) {
                    $points[$playerId] = ($points[$playerId] ?? 0) + $bonus;
                }
            }

            self::awardClutch($round, $roundKills, $guidToPlayerId, $points);
        }

        return $points;
    }

    private static function multiKillBonus(int $kills): float
    {
        return match (true) {
            $kills >= 5 => 5.5,
            $kills === 4 => 3.5,
            $kills === 3 => 2.0,
            $kills === 2 => 1.0,
            default => 0.0,
        };
    }

    private static function clutchBonus(int $enemiesAlive): float
    {
        return match (true) {
            $enemiesAlive >= 4 => 6.0,
            $enemiesAlive === 3 => 4.0,
            $enemiesAlive === 2 => 2.5,
            $enemiesAlive === 1 => 1.5,
            default => 0.0,
        };
    }

    private static function awardClutch(Round $round, Collection $roundKills, array $guidToPlayerId, array &$points): void
    {
        $roster = collect($round->winner_guids ?? []);
        if ($roster->count() < 3) {
            return;
        }

        $enemyGuids = $roundKills->pluck('attacker_guid')->merge($roundKills->pluck('victim_guid'))
            ->filter(fn ($g) => $g && (string) $g !== '0' && ! $roster->contains($g))
            ->unique()->values();

        $teammatesAlive = $roster->count();
        $enemiesAlive = $enemyGuids->count();
        $enemiesAtClutchMoment = null;

        foreach ($roundKills as $kill) {
            if ($kill->victim_guid && $roster->contains($kill->victim_guid)) {
                $teammatesAlive--;
                if ($teammatesAlive === 1 && $enemiesAtClutchMoment === null) {
                    $enemiesAtClutchMoment = $enemiesAlive;
                }
            } elseif ($kill->victim_guid && $enemyGuids->contains($kill->victim_guid)) {
                $enemiesAlive--;
            }
        }

        if ($teammatesAlive !== 1 || $enemiesAtClutchMoment === null) {
            return;
        }

        $deaths = $roundKills->pluck('victim_guid')->filter()->unique();
        $survivors = $roster->diff($deaths);
        if ($survivors->count() !== 1) {
            return;
        }

        $survivorPlayerId = $guidToPlayerId[$survivors->first()] ?? null;
        if (! $survivorPlayerId) {
            return;
        }

        $bonus = self::clutchBonus($enemiesAtClutchMoment);
        if ($bonus > 0) {
            $points[$survivorPlayerId] = ($points[$survivorPlayerId] ?? 0) + $bonus;
        }
    }
}
