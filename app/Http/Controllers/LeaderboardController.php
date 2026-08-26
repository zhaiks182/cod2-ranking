<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use App\Support\TeamSideAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();

        $seasons = Season::orderByDesc('started_at')->get();
        $seasonParam = $request->query('season');
        $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        // Normalizado siempre, aunque venga un codigo crudo de variante en la URL
        // (bookmark viejo, por ejemplo) — ver buildMapGroups()/MapCatalog::mergeVariants
        // para el porque: mp_dawnville_fix y mp_dawnville_sun son el mismo mapa real
        // (St. Mere Eglise) y desde 2026-08-19 comparten una sola pestaña.
        $map = $request->query('map') ? MapCatalog::normalize($request->query('map')) : null;

        $mapGroups = $server ? $this->buildMapGroups($server->id, $matchIds) : collect();

        // Los codigos crudos (variantes) que hay que incluir en cada query de abajo
        // para que la pestaña combinada muestre datos de TODAS sus variantes, no solo
        // la que casualmente quedo de ultima.
        $mapCodes = $map ? ($mapGroups[$map]->codes ?? [$map]) : [];

        // A map played across more than one calendar day can't honestly show one
        // combined "all sessions" total (see the class-level note on buildMapGroups) —
        // picking that map's tab defaults to its most recent session within the
        // selected season/scope instead of silently mixing every session together.
        $from = $to = null;
        if ($map && ($mapGroups[$map]->dates ?? collect())->count() > 1) {
            $latestDate = $mapGroups[$map]->dates->last()->toDateString();
            $from = $to = $latestDate;
        }

        $rows = $server ? $this->aggregateFromKills($server->id, $mapCodes, $matchIds) : collect();

        // Any map tab normally corresponds to exactly one played match (or, for a
        // multi-session map, one specific session once the date default above kicks
        // in) — show the same axis/allies breakdown as that match's own detail page,
        // so the ranking view doesn't need a trip to /partidas just to see who won
        // which side.
        $axisRows = collect();
        $alliesRows = collect();
        $sideScores = ['axis' => null, 'allies' => null, 'winning' => null];

        if ($server && $map) {
            $rounds = Round::where('rounds.server_id', $server->id)->whereIn('rounds.map', $mapCodes)->where('rounds.gametype', 'sd')
                ->whereIn('rounds.match_id', $matchIds)
                ->when($from, fn ($q) => $q->join('matches', 'matches.id', '=', 'rounds.match_id')
                    ->where('matches.started_at', '>=', Carbon::parse($from)->startOfDay())
                    ->where('matches.started_at', '<=', Carbon::parse($to)->endOfDay()))
                ->orderBy('rounds.id')
                ->select('rounds.*')
                ->get();

            [$axisRows, $alliesRows, $sideByPlayerId] = TeamSideAnalyzer::splitByCurrentSide($rounds, $rows);
            $sideScores = TeamSideAnalyzer::sideScores($rounds, $sideByPlayerId);

            if (! $rounds->last()?->ended_at) {
                $sideScores['winning'] = null;
            }
        }

        // Legacy view variables — will be removed in Task 3 when the view is refactored for seasons
        $usingDateFilter = false;

        return view('leaderboard', compact('servers', 'server', 'seasons', 'seasonId', 'mapGroups', 'map', 'mapCodes', 'from', 'to', 'usingDateFilter', 'rows', 'axisRows', 'alliesRows', 'sideScores'));
    }

    /**
     * One entry per REAL map (variant codes like mp_dawnville_fix/mp_dawnville_sun
     * merged under the same normalized key since 2026-08-19 — same map, same tab,
     * see MapCatalog::normalize()), each holding the sorted list of calendar days
     * it's been played on and the raw codes that contributed to it, SCOPED to the
     * selected season's matches ($matchIds) — a map's date-pills inside a season
     * shouldn't include sessions from a different season.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $matchIds
     * @return \Illuminate\Support\Collection<string, object{dates: \Illuminate\Support\Collection<int, \Carbon\Carbon>, codes: array<int, string>}>
     */
    private function buildMapGroups(int $serverId, $matchIds)
    {
        $sessions = GameMatch::where('server_id', $serverId)
            ->where('is_backfilled', false)
            ->where('gametype', 'sd')
            ->whereIn('id', $matchIds)
            ->selectRaw('map, DATE(started_at) as play_date')
            ->distinct()
            ->get()
            ->groupBy(fn ($row) => MapCatalog::normalize($row->map));

        $groups = $sessions->map(fn ($rows) => (object) [
            'dates' => $rows->pluck('play_date')->unique()->map(fn ($d) => Carbon::parse($d))->sort()->values(),
            'codes' => $rows->pluck('map')->unique()->values()->all(),
        ]);

        // Requested tab order: Toujane, Burgundy, Dawnville, Stalingrad first (in that
        // order), then whatever other maps have been played, alphabetically by label.
        $priority = ['mp_toujane', 'mp_burgundy', 'mp_dawnville', 'mp_railyard'];

        return $groups->sortBy(function ($group, $mapCode) use ($priority) {
            $rank = array_search($mapCode, $priority, true);

            return $rank !== false
                ? sprintf('0_%02d', $rank)
                : '1_'.MapCatalog::mapLabel($mapCode);
        });
    }

    /**
     * Unico camino de calculo del ranking desde 2026-08-25 (antes: tablas
     * pre-calculadas player_server_stats/player_map_stats para la vista sin fecha,
     * este metodo solo para el filtro de fecha manual). kills.match_id ya existe
     * directo en la tabla, asi que $matchIds se aplica sin necesidad de otro join
     * mas alla del que ya hace falta para gametype/map (rounds).
     *
     * @param  array<int, string>  $mapCodes  Raw map codes to include (all variants of
     *                                        the selected normalized map, or empty for "General").
     * @param  \Illuminate\Support\Collection<int, int>  $matchIds
     */
    private function aggregateFromKills(int $serverId, array $mapCodes, $matchIds)
    {
        return KillAggregator::aggregate(function () use ($serverId, $mapCodes, $matchIds) {
            // The ranking is Search & Destroy only — a DM/HQ/CTF session shouldn't
            // contribute to it (see StatsRecalculator / ParseCod2Log for the same rule
            // applied to the cached player_map_stats/player_server_stats tables).
            $q = Kill::query()
                ->join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('rounds.server_id', $serverId)->where('rounds.gametype', 'sd')
                ->whereIn('kills.match_id', $matchIds);
            if ($mapCodes) {
                $q->whereIn('rounds.map', $mapCodes);
            }

            return $q;
        });
    }
}
