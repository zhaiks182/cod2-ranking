<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\PlayerMapStat;
use App\Models\PlayerServerStat;
use App\Models\Round;
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

        // Normalizado siempre, aunque venga un codigo crudo de variante en la URL
        // (bookmark viejo, por ejemplo) — ver buildMapGroups()/MapCatalog::mergeVariants
        // para el porque: mp_dawnville_fix y mp_dawnville_sun son el mismo mapa real
        // (St. Mere Eglise) y desde 2026-08-19 comparten una sola pestaña.
        $map = $request->query('map') ? MapCatalog::normalize($request->query('map')) : null;
        $from = $request->query('from');
        $to = $request->query('to');
        $usingDateFilter = (bool) ($from || $to);

        $mapGroups = $server ? $this->buildMapGroups($server->id) : collect();

        // Los codigos crudos (variantes) que hay que incluir en cada query de abajo
        // para que la pestaña combinada muestre datos de TODAS sus variantes, no solo
        // la que casualmente quedo de ultima.
        $mapCodes = $map ? ($mapGroups[$map]->codes ?? [$map]) : [];

        // A map played across more than one calendar day can't honestly show one
        // combined "all sessions" total (see the class-level note on buildMapGroups) —
        // picking that map's tab with no explicit date defaults to its most recent
        // session instead of silently mixing every session together.
        if ($map && ! $usingDateFilter && ($mapGroups[$map]->dates ?? collect())->count() > 1) {
            $latestDate = $mapGroups[$map]->dates->last()->toDateString();
            $from = $to = $latestDate;
            $usingDateFilter = true;
        }

        $rows = collect();

        if ($server) {
            if ($usingDateFilter) {
                $rows = $this->aggregateFromKills($server->id, $mapCodes, $from, $to);
            } elseif ($map) {
                $rows = PlayerMapStat::with('player')
                    ->where('server_id', $server->id)->whereIn('map', $mapCodes)
                    ->where(fn ($q) => $q->where('kills', '>', 0)->orWhere('deaths', '>', 0))
                    ->whereHas('player')
                    ->orderByDesc('kills')
                    ->limit(100)
                    ->get();
            } else {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where(fn ($q) => $q->where('kills', '>', 0)->orWhere('deaths', '>', 0))
                    ->whereHas('player')
                    ->orderByDesc('kills')
                    ->limit(100)
                    ->get();
            }
        }

        // Any map tab normally corresponds to exactly one played match (or, for a
        // multi-session map, one specific session once the date default above kicks
        // in) — show the same axis/allies breakdown as that match's own detail page,
        // so the ranking view doesn't need a trip to /partidas just to see who won
        // which side. Not gated on $usingDateFilter: a single-session map never gets a
        // date filter at all, but should still show its own team panel.
        $axisRows = collect();
        $alliesRows = collect();
        $sideScores = ['axis' => null, 'allies' => null, 'winning' => null];

        if ($server && $map) {
            // Filtered by the owning MATCH's started_at, not the round's own — a
            // session starting at 23:35 can still have a round open past midnight
            // (confirmed: match 23's last round started 00:00:02, technically "the
            // next day" by its own clock), and buildMapGroups() above already buckets
            // that whole session under its start date. Filtering rounds by their own
            // timestamp here would silently drop that last round from the session's
            // one and only date tab.
            $rounds = Round::where('rounds.server_id', $server->id)->whereIn('rounds.map', $mapCodes)->where('rounds.gametype', 'sd')
                ->join('matches', 'matches.id', '=', 'rounds.match_id')
                ->when($from, fn ($q) => $q->where('matches.started_at', '>=', Carbon::parse($from)->startOfDay()))
                ->when($to, fn ($q) => $q->where('matches.started_at', '<=', Carbon::parse($to)->endOfDay()))
                ->orderBy('rounds.id')
                ->select('rounds.*')
                ->get();

            [$axisRows, $alliesRows, $sideByPlayerId] = TeamSideAnalyzer::splitByCurrentSide($rounds, $rows);
            $sideScores = TeamSideAnalyzer::sideScores($rounds, $sideByPlayerId);

            // Same "don't call it yet" rule as the match detail page — only treat it as
            // decided once the last round in scope has actually closed.
            if (! $rounds->last()?->ended_at) {
                $sideScores['winning'] = null;
            }
        }

        return view('leaderboard', compact('servers', 'server', 'mapGroups', 'map', 'mapCodes', 'from', 'to', 'usingDateFilter', 'rows', 'axisRows', 'alliesRows', 'sideScores'));
    }

    /**
     * One entry per REAL map (variant codes like mp_dawnville_fix/mp_dawnville_sun
     * merged under the same normalized key since 2026-08-19 — same map, same tab,
     * see MapCatalog::normalize()), each holding the sorted list of calendar days
     * it's been played on and the raw codes that contributed to it. A map played
     * more than once gets a secondary row of date pills in the view instead of a
     * separate top-level tab per session, so "Toujane" stays one tab in the main
     * row no matter how many days it's been played, with its sessions listed
     * underneath. A map played only once has a single-date list (no secondary row
     * needed).
     *
     * @return \Illuminate\Support\Collection<string, object{dates: \Illuminate\Support\Collection<int, \Carbon\Carbon>, codes: array<int, string>}>
     */
    private function buildMapGroups(int $serverId)
    {
        $sessions = GameMatch::where('server_id', $serverId)
            ->where('is_backfilled', false)
            ->where('gametype', 'sd')
            ->selectRaw('map, DATE(started_at) as play_date')
            ->distinct()
            ->get()
            ->groupBy(fn ($row) => MapCatalog::normalize($row->map));

        $groups = $sessions->map(fn ($rows) => (object) [
            // DATE(started_at) comes back as a plain string from a raw query (this
            // isn't an Eloquent model with date casts) — dedupe the raw strings first
            // (two variant codes played the same day would otherwise show as two
            // separate date pills for the same calendar day) before parsing to Carbon
            // so the view can call date methods on it without every multi-session map
            // 500ing.
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
     * The cached player_map_stats / player_server_stats tables only cover all-time
     * totals, so a date range has to be aggregated live from the kills table instead.
     *
     * @param  array<int, string>  $mapCodes  Raw map codes to include (all variants of
     *                                        the selected normalized map, or empty for "General").
     */
    private function aggregateFromKills(int $serverId, array $mapCodes, ?string $from, ?string $to)
    {
        return KillAggregator::aggregate(function () use ($serverId, $mapCodes, $from, $to) {
            // The ranking is Search & Destroy only — a DM/HQ/CTF session shouldn't
            // contribute to it (see StatsRecalculator / ParseCod2Log for the same rule
            // applied to the cached player_map_stats/player_server_stats tables).
            $q = Kill::query()
                ->join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->join('matches', 'matches.id', '=', 'rounds.match_id')
                ->where('rounds.server_id', $serverId)->where('rounds.gametype', 'sd');
            if ($mapCodes) {
                $q->whereIn('rounds.map', $mapCodes);
            }
            // Filtered by the owning match's started_at (see the $rounds query in
            // index() for the full story) — a late-night session can have kills that
            // land past midnight by its own clock (confirmed: a kill at 00:01:02 from
            // a match that started 23:35:02 the day before), and bucketing by each
            // kill's own occurred_at split that one match across two different
            // "today" totals instead of keeping it under the day it was actually
            // played.
            if ($from) {
                $q->where('matches.started_at', '>=', Carbon::parse($from)->startOfDay());
            }
            if ($to) {
                $q->where('matches.started_at', '<=', Carbon::parse($to)->endOfDay());
            }

            return $q;
        });
    }
}
