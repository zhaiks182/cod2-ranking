<?php

namespace App\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Win rate general del perfil de jugador (2026-08-29, reemplaza la idea original
 * de winrate por mapa -- a pedido del dueño, un solo numero agregado es mas util
 * que una columna por mapa). Reusa TeamSideAnalyzer::winningRosterGuids(), el
 * mismo clustering por overlap de guids que ya calcula el marcador final de una
 * partida -- "gano" para este jugador es "su guid aparece en el roster ganador
 * de esa partida", nada nuevo que mantener sincronizado.
 */
class WinRateCalculator
{
    /**
     * @param  Collection  $matchIds  Ya scopeadas a la temporada elegida (mismo
     *      contrato que el resto del perfil -- GameMatch::forSeason() ya excluye
     *      partidas abandonadas sin resultado real).
     * @return array{wins: int, played: int, rate: float}
     */
    public static function forPlayer(Player $player, Collection $matchIds): array
    {
        $participatedMatchIds = Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.gametype', 'sd')
            ->whereIn('kills.match_id', $matchIds)
            ->where(fn ($q) => $q->where('kills.attacker_player_id', $player->id)->orWhere('kills.victim_player_id', $player->id))
            ->distinct()
            ->pluck('kills.match_id');

        $matches = GameMatch::whereIn('id', $participatedMatchIds)->with('rounds')->get();

        $played = 0;
        $wins = 0;

        foreach ($matches as $match) {
            $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);

            // Sin ganador determinable (empate o rondas insuficientes) -- no cuenta
            // ni como jugada ni como ganada, mismo criterio que final_score cuando
            // devuelve null.
            if ($winningGuids === null) {
                continue;
            }

            $played++;
            if (in_array($player->guid, $winningGuids, true)) {
                $wins++;
            }
        }

        return [
            'wins' => $wins,
            'played' => $played,
            'rate' => $played > 0 ? round(($wins / $played) * 100, 1) : 0.0,
        ];
    }
}
