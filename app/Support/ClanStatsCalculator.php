<?php

namespace App\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Season;

/**
 * Estadisticas agregadas de un clan (2026-09-03) -- suma real de kills/muertes
 * y partidas distintas de TODOS los miembros actuales, mismo criterio SD que
 * el resto del sitio (/ranking, /rango). Los kills no tienen ningun vinculo
 * con clan en la base (el log del juego no sabe de esto) -- por eso esto se
 * calcula al vuelo sobre los player_id de los miembros actuales, nunca "solo
 * lo jugado siendo miembro" (ver docs/superpowers/specs/2026-09-03-clanes-design.md).
 */
class ClanStatsCalculator
{
    public static function aggregate(array $playerIds, int|string|null $seasonId = null): object
    {
        $playerIds = array_values(array_filter($playerIds));

        if (empty($playerIds)) {
            return (object) ['kills' => 0, 'deaths' => 0, 'kd' => 0.0, 'matches' => 0];
        }

        $seasonId ??= Season::current()->id;
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        $sdKills = fn () => Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.gametype', 'sd')
            ->where('kills.is_suicide', false)
            ->whereIn('kills.match_id', $matchIds);

        $kills = $sdKills()->whereIn('kills.attacker_player_id', $playerIds)->count();
        $deaths = $sdKills()->whereIn('kills.victim_player_id', $playerIds)->count();
        $matches = $sdKills()
            ->where(fn ($q) => $q->whereIn('kills.attacker_player_id', $playerIds)->orWhereIn('kills.victim_player_id', $playerIds))
            ->distinct('kills.match_id')
            ->count('kills.match_id');

        return (object) [
            'kills' => $kills,
            'deaths' => $deaths,
            'kd' => $deaths > 0 ? round($kills / $deaths, 2) : (float) $kills,
            'matches' => $matches,
        ];
    }
}
