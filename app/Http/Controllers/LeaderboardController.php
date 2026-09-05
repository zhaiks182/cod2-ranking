<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use App\Support\PlayerRankCalculator;
use App\Support\PlaytimeCalculator;
use App\Support\TeamSideAnalyzer;
use App\Support\WinRateCalculator;
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

        // Filtro de fecha manual (Desde/Hasta), mismo mecanismo y misma UI que ya
        // tiene /partidas — restaurado 2026-08-26 despues de que Task 3 lo sacara del
        // todo asumiendo que el selector de temporada alcanzaba: en la practica el
        // dueño lo sigue necesitando para acotar a un dia puntual (no solo "toda la
        // temporada"), sobre CUALQUIER pestaña (General o un mapa), igual que en
        // /partidas. Explicito en la URL siempre gana. Sin filtro explicito, un mapa
        // jugado mas de una vez en el scope actual sigue sin poder mostrar un solo
        // total honesto para "todas las sesiones combinadas" (ver la nota de clase en
        // buildMapGroups) — por eso ese caso sigue cayendo a la sesion mas reciente
        // por default, como ya hacia antes de este cambio.
        $requestedFrom = $request->query('from');
        $requestedTo = $request->query('to');
        $usingDateFilter = (bool) ($requestedFrom || $requestedTo);

        if ($usingDateFilter) {
            $from = $requestedFrom;
            $to = $requestedTo;
        } elseif ($map && ($mapGroups[$map]->dates ?? collect())->count() > 1) {
            $from = $to = $mapGroups[$map]->dates->last()->toDateString();
        } else {
            $from = $to = null;
        }

        // La tabla del ranking tiene que coincidir con el panel de Axis/Allies de mas
        // abajo (que ya aplica el mismo from/to) — sin esto, un filtro de fecha
        // acotaba el panel pero la tabla seguia sumando toda la temporada.
        $tableMatchIds = $matchIds;
        if ($from || $to) {
            $tableMatchIds = GameMatch::whereIn('id', $matchIds)
                ->when($from, fn ($q) => $q->where('started_at', '>=', Carbon::parse($from)->startOfDay()))
                ->when($to, fn ($q) => $q->where('started_at', '<=', Carbon::parse($to)->endOfDay()))
                ->pluck('id');
        }

        $rows = $server ? $this->aggregateFromKills($server->id, $mapCodes, $tableMatchIds) : collect();

        // Horas jugadas / kills por hora (reemplaza "dias jugados", 2026-08-29, a
        // pedido del dueño: "mas preciso"). Mismo calculo que /horas-jugadas
        // (PlaytimeCalculator, extraido de ahi el mismo dia para no duplicarlo),
        // scopeado igual que el resto de esta tabla (server+matchIds+mapCodes).
        if ($server) {
            $secondsByPlayer = PlaytimeCalculator::secondsByPlayer($server->id, $tableMatchIds, $mapCodes);

            // "Partidas" (2026-09-05, a pedido del dueño) -- mismo proxy de
            // "jugó esta partida" que ya usa PlayerRankCalculator para el
            // mínimo de /rango (apareció como atacante o víctima en al
            // menos una baja SD de esa partida), reusado en vez de
            // inventar un conteo aparte.
            $matchesByPlayer = PlayerRankCalculator::matchesPlayedByPlayer($server->id, $tableMatchIds, $mapCodes);

            // "Partidas ganadas" (2026-09-05, seguimiento directo de "Partidas") --
            // $tableMatchIds no está acotado por mapa por sí solo (solo por fecha),
            // así que hay que pasarle $mapCodes igual que matchesPlayedByPlayer.
            $wonByPlayer = WinRateCalculator::wonMatchesCountByPlayer($tableMatchIds, $mapCodes);

            $rows = $rows->map(function ($row) use ($secondsByPlayer, $matchesByPlayer, $wonByPlayer) {
                $hours = round(($secondsByPlayer[$row->player->id] ?? 0) / 3600, 1);
                $row->hours_played = $hours;
                $row->kills_per_hour = $hours > 0 ? round($row->kills / $hours, 1) : 0.0;
                $row->matches_played = $matchesByPlayer[$row->player->id] ?? 0;
                $row->matches_won = $wonByPlayer[$row->player->id] ?? 0;

                return $row;
            });
        }

        // Any map tab normally corresponds to exactly one played match (or, for a
        // multi-session map, one specific session once the date default above kicks
        // in) — show the same axis/allies breakdown as that match's own detail page,
        // so the ranking view doesn't need a trip to /partidas just to see who won
        // which side.
        $axisRows = collect();
        $alliesRows = collect();
        $sideScores = ['axis' => null, 'allies' => null, 'winning' => null];

        if ($server && $map) {
            // $tableMatchIds (no $matchIds) a proposito -- mismo scope de fecha que ya
            // se le aplico a la tabla de arriba, para que el panel nunca desacuerde con
            // ella (ver el comentario de $tableMatchIds mas arriba).
            $rounds = Round::where('rounds.server_id', $server->id)->whereIn('rounds.map', $mapCodes)->where('rounds.gametype', 'sd')
                ->whereIn('rounds.match_id', $tableMatchIds)
                ->orderBy('rounds.id')
                ->select('rounds.*')
                ->get();

            [$axisRows, $alliesRows, $sideByPlayerId] = TeamSideAnalyzer::splitByCurrentSide($rounds, $rows);
            $sideScores = TeamSideAnalyzer::sideScores($rounds, $sideByPlayerId);

            if (! $rounds->last()?->ended_at) {
                $sideScores['winning'] = null;
            }
        }

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
