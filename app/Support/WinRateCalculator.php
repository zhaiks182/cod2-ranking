<?php

namespace App\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Win rate del perfil de jugador (2026-08-29, general; 2026-08-29 tambien por
 * mapa). Reusa TeamSideAnalyzer::winningRosterGuids(), el mismo clustering por
 * overlap de guids que ya calcula el marcador final de una partida -- "gano"
 * para este jugador es "su guid aparece en el roster ganador de esa partida",
 * nada nuevo que mantener sincronizado.
 */
class WinRateCalculator
{
    /**
     * Partidas SD (con ganador determinable) donde el jugador participo, cada una
     * con si ese jugador gano o no -- base compartida entre forPlayer() (agregado)
     * y byMapForPlayer() (desglosado por mapa), para no duplicar la query de
     * participacion ni el clustering.
     *
     * @param  Collection  $matchIds  Ya scopeadas a la temporada elegida (mismo
     *      contrato que el resto del perfil -- GameMatch::forSeason() ya excluye
     *      partidas abandonadas sin resultado real).
     * @return Collection<int, object{match: GameMatch, won: bool}>
     */
    private static function decidedMatchesForPlayer(Player $player, Collection $matchIds): Collection
    {
        $participatedMatchIds = Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.gametype', 'sd')
            ->whereIn('kills.match_id', $matchIds)
            ->where(fn ($q) => $q->where('kills.attacker_player_id', $player->id)->orWhere('kills.victim_player_id', $player->id))
            ->distinct()
            ->pluck('kills.match_id');

        $matches = GameMatch::whereIn('id', $participatedMatchIds)->with('rounds')->get();

        return $matches
            ->map(function ($match) use ($player) {
                $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);

                // Sin ganador determinable (empate o rondas insuficientes) -- no
                // cuenta ni como jugada ni como ganada, mismo criterio que
                // final_score cuando devuelve null.
                if ($winningGuids === null) {
                    return null;
                }

                return (object) ['match' => $match, 'won' => in_array($player->guid, $winningGuids, true)];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array{wins: int, played: int, rate: float}
     */
    public static function forPlayer(Player $player, Collection $matchIds): array
    {
        $decided = self::decidedMatchesForPlayer($player, $matchIds);
        $played = $decided->count();
        $wins = $decided->where('won', true)->count();

        return [
            'wins' => $wins,
            'played' => $played,
            'rate' => $played > 0 ? round(($wins / $played) * 100, 1) : 0.0,
        ];
    }

    /**
     * Mismo calculo, desglosado por mapa (codigo normalizado -- variantes
     * comunitarias del mismo mapa real, ej. mp_dawnville_fix/_sun, se fusionan
     * en una sola fila, mismo criterio que MapCatalog::mergeVariants()).
     *
     * @return Collection<int, object{map: string, played: int, wins: int, rate: float}>
     */
    public static function byMapForPlayer(Player $player, Collection $matchIds): Collection
    {
        $decided = self::decidedMatchesForPlayer($player, $matchIds);

        return $decided
            ->groupBy(fn ($row) => MapCatalog::normalize($row->match->map))
            ->map(function ($group, $mapCode) {
                $played = $group->count();
                $wins = $group->where('won', true)->count();

                return (object) [
                    'map' => $mapCode,
                    'played' => $played,
                    'wins' => $wins,
                    'rate' => round($wins / $played * 100, 1),
                ];
            })
            ->sortByDesc('played')
            ->values();
    }
}
