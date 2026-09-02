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
        $participatingKills = Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.gametype', 'sd')
            ->whereIn('kills.match_id', $matchIds)
            ->where(fn ($q) => $q->where('kills.attacker_player_id', $player->id)->orWhere('kills.victim_player_id', $player->id))
            ->get(['kills.match_id', 'kills.attacker_player_id', 'kills.attacker_guid', 'kills.victim_player_id', 'kills.victim_guid'])
            ->groupBy('match_id');

        $matches = GameMatch::whereIn('id', $participatingKills->keys())->with('rounds')->get();

        return $matches
            ->map(function ($match) use ($player, $participatingKills) {
                $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);

                // Sin ganador determinable (empate o rondas insuficientes) -- no
                // cuenta ni como jugada ni como ganada, mismo criterio que
                // final_score cuando devuelve null.
                if ($winningGuids === null) {
                    return null;
                }

                // El jugador puede haber jugado ESTA partida puntual bajo cualquier
                // guid que alguna vez tuvo -- PlayerMerger (ver CLAUDE.md, "Fusionar
                // jugadores") repunta attacker_player_id/victim_player_id al fusionar
                // perfiles, pero nunca reescribe el guid historico de cada kill. Usar
                // el guid actual y fijo del jugador ($player->guid) rompía el win
                // rate de cualquier partida jugada antes de un merge -- hay que
                // mirar con que guid jugo ESTA partida especifica.
                $guidsInThisMatch = $participatingKills[$match->id]
                    ->flatMap(fn ($kill) => [
                        $kill->attacker_player_id === $player->id ? $kill->attacker_guid : null,
                        $kill->victim_player_id === $player->id ? $kill->victim_guid : null,
                    ])
                    ->filter(fn ($guid) => $guid !== null)
                    ->unique();

                return (object) ['match' => $match, 'won' => $guidsInThisMatch->intersect($winningGuids)->isNotEmpty()];
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

    /**
     * Historial cronologico (mas reciente primero) de las mismas partidas
     * decididas de arriba, sin agrupar -- una fila por partida, para que el
     * perfil pueda mostrar "cuando" se jugo cada una, no solo el total.
     *
     * @return Collection<int, object{match: GameMatch, won: bool}>
     */
    public static function matchHistoryForPlayer(Player $player, Collection $matchIds): Collection
    {
        return self::decidedMatchesForPlayer($player, $matchIds)
            ->sortByDesc(fn ($row) => $row->match->started_at)
            ->values();
    }
}
