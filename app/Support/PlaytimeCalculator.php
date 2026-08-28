<?php

namespace App\Support;

use App\Models\Kill;
use App\Models\Round;
use Illuminate\Support\Collection;

/**
 * Extraido de SpecialtyController::playtime() (2026-08-29) para reusar el mismo
 * calculo de "tiempo jugado" en /ranking (columnas Horas/Kills por hora, a
 * pedido de un jugador: "mas preciso que dias jugados") sin duplicar la logica.
 *
 * No hay ninguna tabla que guarde "el jugador estuvo conectado de tal hora a
 * tal hora" -- el proxy real es la duracion de las rondas SD en las que el
 * jugador tuvo al menos un kill o una muerte (misma señal de participacion
 * que ya usa KillAggregator para "dias jugados", solo que sumando segundos de
 * ronda en vez de contar dias distintos).
 */
class PlaytimeCalculator
{
    /**
     * @param  ?int  $serverId  null = todos los servidores -- usado por el perfil de
     *      jugador, que no scopea por server (un jugador es global, ver "Multi-servidor"
     *      en el CLAUDE.md del repo).
     * @param  Collection  $matchIds
     * @param  array<int, string>  $mapCodes  Codigos de mapa a incluir, vacio = todos.
     * @return array<int, int> player_id => segundos jugados
     */
    public static function secondsByPlayer(?int $serverId, Collection $matchIds, array $mapCodes = []): array
    {
        $rounds = Round::when($serverId, fn ($q) => $q->where('server_id', $serverId))
            ->where('gametype', 'sd')
            ->whereNotNull('ended_at')
            ->whereIn('match_id', $matchIds)
            ->when($mapCodes, fn ($q) => $q->whereIn('map', $mapCodes))
            ->get(['id', 'started_at', 'ended_at'])
            ->keyBy('id');

        $kills = Kill::whereIn('round_id', $rounds->keys())
            ->where(function ($q) {
                $q->whereNotNull('attacker_player_id')->orWhereNotNull('victim_player_id');
            })
            ->get(['attacker_player_id', 'victim_player_id', 'round_id']);

        $roundPlayers = [];
        foreach ($kills as $kill) {
            if ($kill->attacker_player_id) {
                $roundPlayers[$kill->round_id][$kill->attacker_player_id] = true;
            }
            if ($kill->victim_player_id) {
                $roundPlayers[$kill->round_id][$kill->victim_player_id] = true;
            }
        }

        $seconds = [];
        foreach ($roundPlayers as $roundId => $playerIds) {
            $round = $rounds->get($roundId);
            if (! $round) {
                continue;
            }
            $duration = $round->started_at->diffInSeconds($round->ended_at);
            foreach (array_keys($playerIds) as $playerId) {
                $seconds[$playerId] = ($seconds[$playerId] ?? 0) + $duration;
            }
        }

        return $seconds;
    }

    public static function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return "{$hours}h {$remaining}min";
    }
}
