<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\PlayerServerStat;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Services\GeoIp;
use App\Support\KillAggregator;
use App\Support\PlayerRankCalculator;
use App\Support\TeamSideAnalyzer;
use Illuminate\Http\Request;

class SpecialtyController extends Controller
{
    public function grenades(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalGrenadeKills = 0;
        $totalKills = 0;
        $favoriteGrenade = null;

        if ($server) {
            $all = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds));

            $rows = $all->filter(fn ($row) => $row->grenade_kills > 0)
                ->map(function ($row) {
                    $row->value = $row->grenade_kills;
                    $row->share = $row->kills > 0 ? round($row->grenade_kills / $row->kills * 100, 1) : 0;

                    return $row;
                })
                ->sortByDesc('value')->take(50)->values();

            $totalGrenadeKills = $all->sum('grenade_kills');
            $totalKills = $all->sum('kills');

            $favoriteGrenade = $this->sdKills($server->id, $matchIds)
                ->where('kills.is_grenade', true)
                ->selectRaw('kills.weapon, count(*) as uses')
                ->groupBy('kills.weapon')
                ->orderByDesc('uses')
                ->first();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.grenades', 'icon' => '💣', 'title' => 'Especialistas en Granadas',
            'subtitle' => 'Ranking de bajas con granada — Search and Destroy, '.($server?->name ?? 'servidor'),
            'valueLabel' => 'granadas', 'valueColor' => 'text-amber-400',
            'shareLabel' => '% de sus bajas',
            'statCards' => [
                ['label' => 'Bajas con granada', 'value' => $totalGrenadeKills, 'color' => 'text-amber-400'],
                ['label' => '% del total de bajas', 'value' => $totalKills > 0 ? round($totalGrenadeKills / $totalKills * 100, 1).'%' : '0%'],
                ['label' => 'Granada favorita', 'value' => $favoriteGrenade ? \App\Support\WeaponCatalog::label($favoriteGrenade->weapon) : '—'],
            ],
        ]);
    }

    public function headshots(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalHeadshots = 0;
        $totalKills = 0;

        if ($server) {
            $all = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds));

            $rows = $all->filter(fn ($row) => $row->headshots > 0)
                ->map(function ($row) {
                    $row->value = $row->headshots;
                    $row->share = $row->kills > 0 ? round($row->headshots / $row->kills * 100, 1) : 0;

                    return $row;
                })
                ->sortByDesc('value')->take(50)->values();

            $totalHeadshots = $all->sum('headshots');
            $totalKills = $all->sum('kills');
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.headshots', 'icon' => '🎯', 'title' => 'Headshots',
            'subtitle' => 'Ranking de headshots — Search and Destroy, '.($server?->name ?? 'servidor'),
            'valueLabel' => 'headshots', 'valueColor' => 'text-rose-400',
            'shareLabel' => '% de sus bajas',
            'statCards' => [
                ['label' => 'Total de headshots', 'value' => $totalHeadshots, 'color' => 'text-rose-400'],
                ['label' => '% del total de bajas', 'value' => $totalKills > 0 ? round($totalHeadshots / $totalKills * 100, 1).'%' : '0%'],
            ],
        ]);
    }

    public function friendlyFire(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalTeamkills = 0;

        if ($server) {
            $all = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds));

            $rows = $all->filter(fn ($row) => $row->teamkills > 0)
                ->map(function ($row) {
                    $row->value = $row->teamkills;
                    $row->share = $row->kills > 0 ? round($row->teamkills / $row->kills * 100, 1) : 0;

                    return $row;
                })
                ->sortByDesc('value')->take(50)->values();

            $totalTeamkills = $all->sum('teamkills');
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.friendly-fire', 'icon' => '💀', 'title' => 'Fuego amigo',
            'subtitle' => 'Los que más matan a sus propios compañeros — con cariño',
            'valueLabel' => 'compañeros', 'valueColor' => 'text-red-400',
            'shareLabel' => '% de sus bajas',
            'statCards' => [
                ['label' => 'Total de fuego amigo', 'value' => $totalTeamkills, 'color' => 'text-red-400'],
            ],
        ]);
    }

    public function suicides(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalSuicides = 0;

        if ($server) {
            $counts = Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('rounds.server_id', $server->id)
                ->where('rounds.gametype', 'sd')
                ->where('kills.is_suicide', true)
                ->whereNotNull('kills.attacker_player_id')
                ->whereIn('kills.match_id', $matchIds)
                ->selectRaw('kills.attacker_player_id as player_id, count(*) as c')
                ->groupBy('kills.attacker_player_id')
                ->orderByDesc('c')
                ->limit(50)
                ->get();

            $players = Player::whereIn('id', $counts->pluck('player_id'))->get()->keyBy('id');

            $rows = $counts->map(function ($row) use ($players) {
                $player = $players[$row->player_id] ?? null;

                return $player ? (object) ['player' => $player, 'value' => $row->c, 'share' => null] : null;
            })->filter()->values();

            $totalSuicides = (int) $counts->sum('c');
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.suicides', 'icon' => '🤡', 'title' => 'Suicidios',
            'subtitle' => 'Los que más se matan solos (granada en la mano, caídas, etc.)',
            'valueLabel' => 'suicidios', 'valueColor' => 'text-fuchsia-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Total de suicidios', 'value' => $totalSuicides, 'color' => 'text-fuchsia-400'],
            ],
        ]);
    }

    /** Died to someone else's grenade — self-frags are already covered by suicides()/sdKills() excludes them. */
    public function grenadeDeaths(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalGrenadeDeaths = 0;

        if ($server) {
            $counts = $this->sdKills($server->id, $matchIds)
                ->where('kills.is_grenade', true)
                ->whereNotNull('kills.victim_player_id')
                ->selectRaw('kills.victim_player_id as player_id, count(*) as c')
                ->groupBy('kills.victim_player_id')
                ->orderByDesc('c')
                ->limit(50)
                ->get();

            $totalsByPlayer = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds))->keyBy('player.id');
            $players = Player::whereIn('id', $counts->pluck('player_id'))->get()->keyBy('id');

            $rows = $counts->map(function ($row) use ($players, $totalsByPlayer) {
                $player = $players[$row->player_id] ?? null;
                if (! $player) {
                    return null;
                }

                return (object) [
                    'player' => $player,
                    'value' => $row->c,
                    'share' => null,
                    'kills' => $totalsByPlayer[$row->player_id]->kills ?? null,
                ];
            })->filter()->values();

            $totalGrenadeDeaths = (int) $counts->sum('c');
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.grenade-deaths', 'icon' => '🪦', 'title' => 'Muertes por granada',
            'subtitle' => 'Los que más mueren por granadas ajenas (no cuenta autoeliminarse) — Search and Destroy',
            'valueLabel' => 'muertes por nade', 'valueColor' => 'text-lime-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Total de muertes por nade', 'value' => $totalGrenadeDeaths, 'color' => 'text-lime-400'],
            ],
        ]);
    }

    public function efficiency(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $minKills = 20;

        if ($server) {
            $all = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds));

            $rows = $all->filter(fn ($row) => $row->kills >= $minKills)
                ->map(function ($row) {
                    $row->value = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills;
                    $row->share = null;

                    return $row;
                })
                ->sortByDesc('value')
                ->values()
                ->take(50);
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.efficiency', 'icon' => '⚔️', 'title' => 'Los Más Eficientes',
            'subtitle' => "Mejor ratio kills/muertes (K/D) — mínimo {$minKills} bajas para entrar al ranking",
            'valueLabel' => 'K/D', 'valueColor' => 'text-emerald-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Mínimo de bajas para calificar', 'value' => $minKills],
            ],
        ]);
    }

    public function mapsWon(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalMaps = 0;

        if ($server) {
            $matches = GameMatch::where('server_id', $server->id)
                ->where('is_backfilled', false)
                ->where('gametype', 'sd')
                ->whereNotNull('ended_at')
                ->whereIn('id', $matchIds)
                ->with('rounds:id,match_id,winner_guids')
                ->get();

            $tally = [];
            foreach ($matches as $match) {
                $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);
                if (! $winningGuids) {
                    continue;
                }

                $totalMaps++;
                foreach ($winningGuids as $guid) {
                    $tally[$guid] = ($tally[$guid] ?? 0) + 1;
                }
            }

            arsort($tally);
            $tally = array_slice($tally, 0, 50, true);

            $players = Player::whereIn('guid', array_keys($tally))->get()->keyBy('guid');

            $rows = collect($tally)->map(function ($count, $guid) use ($players) {
                $player = $players[$guid] ?? null;
                if (! $player) {
                    return null;
                }

                return (object) ['player' => $player, 'value' => $count, 'share' => null];
            })->filter()->values();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.maps-won', 'icon' => '🏆', 'title' => 'Mapas Ganados',
            'subtitle' => "Partidas completas ganadas por jugador (de {$totalMaps} mapas jugados y decididos)",
            'valueLabel' => 'mapas', 'valueColor' => 'text-yellow-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Mapas jugados y decididos', 'value' => $totalMaps],
            ],
        ]);
    }

    public function weapons(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $weapons = collect();

        if ($server) {
            $totals = $this->sdKills($server->id, $matchIds)
                ->selectRaw('kills.weapon, count(*) as uses')
                ->groupBy('kills.weapon')
                ->orderByDesc('uses')
                ->limit(20)
                ->get();

            $killersByWeapon = $this->sdKills($server->id, $matchIds)
                ->whereNotNull('kills.attacker_player_id')
                ->selectRaw('kills.weapon, kills.attacker_player_id, count(*) as uses')
                ->groupBy('kills.weapon', 'kills.attacker_player_id')
                ->get()
                ->groupBy('weapon')
                ->map(fn ($rows) => $rows->sortByDesc('uses')->values());

            $players = Player::whereIn('id', $killersByWeapon->flatten(1)->pluck('attacker_player_id')->unique())
                ->get()->keyBy('id');

            $weapons = $totals->map(function ($row) use ($killersByWeapon, $players) {
                $killers = $killersByWeapon->get($row->weapon, collect());
                $top = $killers->first();
                $topPlayer = $top ? ($players[$top->attacker_player_id] ?? null) : null;

                // Full breakdown for the "click to see everyone" popup — not just the
                // top killer shown in the table row.
                $allKillers = $killers->map(function ($k) use ($players) {
                    $player = $players[$k->attacker_player_id] ?? null;

                    return $player ? ['guid' => $player->guid, 'name' => $player->last_name_plain, 'uses' => $k->uses] : null;
                })->filter()->values();

                return (object) [
                    'weapon' => $row->weapon,
                    'uses' => $row->uses,
                    'topPlayer' => $topPlayer,
                    'topUses' => $top->uses ?? 0,
                    'allKillers' => $allKillers,
                ];
            });
        }

        return view('specialties.weapons', compact('servers', 'server', 'seasons', 'seasonId', 'weapons'));
    }

    public function rivalries(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rivalries = collect();

        if ($server) {
            $pairs = $this->sdKills($server->id, $matchIds)
                ->whereNotNull('kills.attacker_player_id')->whereNotNull('kills.victim_player_id')
                ->selectRaw('kills.attacker_player_id, kills.victim_player_id, count(*) as kills_count')
                ->groupBy('kills.attacker_player_id', 'kills.victim_player_id')
                ->having('kills_count', '>=', 3)
                ->get();

            // A vs B and B vs A are the same matchup — group by the *unordered* pair
            // so each rivalry appears once, with whichever direction has more kills as
            // "the" verdugo/víctima and the other direction kept as the reverse count
            // (shown in the click-to-expand detail).
            $deduped = $pairs->groupBy(function ($row) {
                $ids = [$row->attacker_player_id, $row->victim_player_id];
                sort($ids);

                return implode('-', $ids);
            })->map(function ($group) {
                $sorted = $group->sortByDesc('kills_count')->values();
                $dominant = $sorted[0];

                return (object) [
                    'attacker_player_id' => $dominant->attacker_player_id,
                    'victim_player_id' => $dominant->victim_player_id,
                    'kills_count' => $dominant->kills_count,
                    'reverse_count' => $sorted->get(1)->kills_count ?? 0,
                ];
            })->sortByDesc('kills_count')->take(50)->values();

            $playerIds = $deduped->pluck('attacker_player_id')->merge($deduped->pluck('victim_player_id'))->unique();
            $players = Player::whereIn('id', $playerIds)->get()->keyBy('id');

            // Favorite weapon in each dominant matchup — a different cut of the same
            // kills already queried above, grouped one level finer (by weapon too).
            $topWeaponByPair = $this->sdKills($server->id, $matchIds)
                ->whereNotNull('kills.attacker_player_id')->whereNotNull('kills.victim_player_id')
                ->selectRaw('kills.attacker_player_id, kills.victim_player_id, kills.weapon, count(*) as c')
                ->groupBy('kills.attacker_player_id', 'kills.victim_player_id', 'kills.weapon')
                ->get()
                ->groupBy(fn ($r) => $r->attacker_player_id.'-'.$r->victim_player_id)
                ->map(fn ($g) => $g->sortByDesc('c')->first());

            $rivalries = $deduped->map(function ($row) use ($players, $topWeaponByPair) {
                $topWeapon = $topWeaponByPair->get($row->attacker_player_id.'-'.$row->victim_player_id);

                return (object) [
                    'nemesis' => $players[$row->attacker_player_id] ?? null,
                    'victim' => $players[$row->victim_player_id] ?? null,
                    'count' => $row->kills_count,
                    'reverseCount' => $row->reverse_count,
                    'weapon' => $topWeapon?->weapon,
                ];
            })->filter(fn ($r) => $r->nemesis && $r->victim)->values();
        }

        return view('specialties.rivalries', compact('servers', 'server', 'seasons', 'seasonId', 'rivalries'));
    }

    public function mapKings(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $maps = collect();

        if ($server) {
            // Una fila por codigo de mapa CRUDO (no fusionado) a pedido explicito --
            // mp_dawnville_fix y mp_dawnville_sun quedan separados. Se agrega el codigo
            // crudo debajo del nombre bonito para que dos filas con la misma etiqueta
            // ("St. Mere Eglise, France") no se vean como si fueran un duplicado.
            $maps = KillAggregator::topByMap(fn () => $this->sdKills($server->id, $matchIds))
                ->map(fn ($m) => tap($m, fn ($m) => $m->mapLabel = \App\Support\MapCatalog::mapLabel($m->map)));
        }

        return view('specialties.map-kings', compact('servers', 'server', 'seasons', 'seasonId', 'maps'));
    }

    public function playtime(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalSeconds = 0;

        if ($server) {
            $rounds = Round::where('server_id', $server->id)
                ->where('gametype', 'sd')
                ->whereNotNull('ended_at')
                ->whereIn('match_id', $matchIds)
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

            arsort($seconds);
            $totalSeconds = array_sum($seconds);
            $totalPlayers = count($seconds);
            $seconds = array_slice($seconds, 0, 50, true);

            $players = Player::whereIn('id', array_keys($seconds))->get()->keyBy('id');

            $rows = collect($seconds)->map(function ($sec, $playerId) use ($players) {
                $player = $players[$playerId] ?? null;
                if (! $player) {
                    return null;
                }

                return (object) ['player' => $player, 'value' => $this->formatDuration($sec), 'share' => null];
            })->filter()->values();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.playtime', 'icon' => '⏱️', 'title' => 'Más Horas Jugadas',
            'subtitle' => 'Tiempo estimado en partida — suma la duración de las rondas SD en las que participó cada jugador',
            'valueLabel' => 'tiempo', 'valueColor' => 'text-sky-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Tiempo total registrado', 'value' => $this->formatDuration($totalSeconds), 'color' => 'text-sky-400'],
                ['label' => 'Jugadores con tiempo registrado', 'value' => $totalPlayers],
            ],
        ]);
    }

    public function streaks(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $longestEver = null;

        if ($server) {
            $matches = GameMatch::where('server_id', $server->id)
                ->where('is_backfilled', false)
                ->where('gametype', 'sd')
                ->whereNotNull('ended_at')
                ->whereIn('id', $matchIds)
                ->orderBy('started_at')
                ->with('rounds:id,match_id,winner_guids')
                ->get();

            $current = [];
            $best = [];

            foreach ($matches as $match) {
                $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);
                if (! $winningGuids) {
                    continue;
                }
                $winningGuids = array_flip($winningGuids);

                $kills = Kill::where('match_id', $match->id)->get(['attacker_guid', 'victim_guid']);
                $participantGuids = $kills->pluck('attacker_guid')->merge($kills->pluck('victim_guid'))
                    ->filter(fn ($g) => $g && $g !== '0')->unique();

                foreach ($participantGuids as $guid) {
                    if (isset($winningGuids[$guid])) {
                        $current[$guid] = ($current[$guid] ?? 0) + 1;
                        $best[$guid] = max($best[$guid] ?? 0, $current[$guid]);
                    } else {
                        $current[$guid] = 0;
                    }
                }
            }

            arsort($best);
            $best = array_filter($best, fn ($v) => $v >= 2);
            $best = array_slice($best, 0, 50, true);

            $players = Player::whereIn('guid', array_keys($best))->get()->keyBy('guid');

            $rows = collect($best)->map(function ($bestStreak, $guid) use ($players, $current) {
                $player = $players[$guid] ?? null;
                if (! $player) {
                    return null;
                }

                return (object) [
                    'player' => $player,
                    'best' => $bestStreak,
                    'current' => $current[$guid] ?? 0,
                ];
            })->filter()->values();

            $longestEver = $rows->first();
        }

        return view('specialties.streaks', compact('servers', 'server', 'seasons', 'seasonId', 'rows', 'longestEver'));
    }

    public function recentActivity(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $since = now()->subDays(7);
        $totalKills = 0;

        if ($server) {
            $tally = $this->sdKills($server->id, $matchIds)
                ->whereNotNull('kills.attacker_player_id')
                ->where('kills.occurred_at', '>=', $since)
                ->selectRaw('kills.attacker_player_id as player_id, count(*) as kills_count')
                ->groupBy('kills.attacker_player_id')
                ->orderByDesc('kills_count')
                ->limit(50)
                ->get();

            $totalKills = (int) $this->sdKills($server->id, $matchIds)->where('kills.occurred_at', '>=', $since)->count();

            $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');

            $rows = $tally->map(function ($row) use ($players) {
                $player = $players[$row->player_id] ?? null;
                if (! $player) {
                    return null;
                }

                return (object) ['player' => $player, 'value' => $row->kills_count, 'share' => null, 'kills' => $row->kills_count];
            })->filter()->values();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.recent-activity', 'icon' => '📈', 'title' => 'Actividad Reciente',
            'subtitle' => 'Más bajas (Search and Destroy) en los últimos 7 días — '.$since->translatedFormat('d M').' a hoy',
            'valueLabel' => 'bajas', 'valueColor' => 'text-lime-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Bajas en los últimos 7 días', 'value' => $totalKills, 'color' => 'text-lime-400'],
                ['label' => 'Jugadores activos', 'value' => $rows->count()],
            ],
        ]);
    }

    public function countries(Request $request)
    {
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        // Sigue sin selector de server (ver comentario original) -- $matchIds ya viene
        // de GameMatch::forSeason(), que no filtra por server, asi que cubre todos los
        // servers activos de una.
        $activePlayerIds = Kill::whereIn('match_id', $matchIds)
            ->whereNotNull('attacker_player_id')
            ->distinct()
            ->pluck('attacker_player_id')
            ->merge(
                Kill::whereIn('match_id', $matchIds)
                    ->whereNotNull('victim_player_id')
                    ->distinct()
                    ->pluck('victim_player_id')
            )
            ->unique();

        $players = Player::whereNotNull('ip')->whereIn('id', $activePlayerIds)
            ->get(['id', 'guid', 'last_name', 'last_name_plain', 'ip', 'kills_total']);

        $grouped = [];
        foreach ($players as $player) {
            $country = GeoIp::countryFor($player->ip);
            if (! $country) {
                continue;
            }
            $grouped[$country['code']]['name'] ??= $country['name'];
            $grouped[$country['code']]['players'][] = $player;
        }

        $totalWithCountry = array_sum(array_map(fn ($g) => count($g['players']), $grouped));

        $countries = collect($grouped)->map(function ($g, $code) use ($totalWithCountry) {
            $players = collect($g['players'])->sortByDesc('kills_total')->values();

            return (object) [
                'code' => $code,
                'name' => $g['name'],
                'flag' => GeoIp::flagIconHtml($code),
                'count' => $players->count(),
                'share' => $totalWithCountry > 0 ? round($players->count() / $totalWithCountry * 100, 1) : 0,
                'players' => $players,
            ];
        })->sortByDesc('count')->values();

        return view('specialties.countries', [
            'countries' => $countries,
            'totalWithCountry' => $totalWithCountry,
            'totalPlayers' => $players->count(),
            'seasons' => $seasons,
            'seasonId' => $seasonId,
        ]);
    }

    public function clutches(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalClutches = 0;

        if ($server) {
            $rounds = Round::where('server_id', $server->id)->where('gametype', 'sd')
                ->whereNotNull('winner_guids')
                ->whereIn('match_id', $matchIds)
                ->whereHas('match', fn ($q) => $q->where('is_backfilled', false))
                ->get(['id', 'winner_guids']);

            // winner_guids is the WHOLE winning roster from the round's Winners; line
            // (confirmed against real matches: 7 guids, one per player on that side),
            // not just who was still alive when the round ended — so "count===1" (the
            // original check here) only ever fires if nearly the whole team had
            // DISCONNECTED before the round finished, which is why it found exactly 1
            // round in the server's entire history despite players clearly clutching
            // rounds in-game. A real clutch is the roster minus whoever on it died
            // *during* that round (a Kill row with them as victim, round_id-scoped)
            // leaving exactly one survivor — re-running this against a single day's
            // matches found 17 genuine 1vX rounds the old check missed entirely.
            $deathsByRound = Kill::whereIn('round_id', $rounds->pluck('id'))
                ->whereNotNull('victim_guid')
                ->get(['round_id', 'victim_guid'])
                ->groupBy('round_id');

            $tally = [];
            foreach ($rounds as $round) {
                $roster = collect($round->winner_guids);
                // A roster that already had only 1-2 people before the round even
                // started (mass disconnects) isn't a clutch — there's no "X" to have
                // been alone against. Needs a real team whittled down mid-round.
                if ($roster->count() < 3) {
                    continue;
                }

                $deaths = $deathsByRound->get($round->id, collect())->pluck('victim_guid')->unique();
                $survivors = $roster->diff($deaths);

                if ($survivors->count() === 1) {
                    $guid = $survivors->first();
                    $tally[$guid] = ($tally[$guid] ?? 0) + 1;
                    $totalClutches++;
                }
            }

            arsort($tally);
            $tally = array_slice($tally, 0, 50, true);
            $players = Player::whereIn('guid', array_keys($tally))->get()->keyBy('guid');

            $rows = collect($tally)->map(function ($count, $guid) use ($players) {
                $player = $players[$guid] ?? null;

                return $player ? (object) ['player' => $player, 'value' => $count, 'share' => null] : null;
            })->filter()->values();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.clutches', 'icon' => '🥶', 'title' => 'Clutches 1vX',
            'subtitle' => 'Rondas ganadas siendo el único sobreviviente de su equipo',
            'valueLabel' => 'clutches', 'valueColor' => 'text-cyan-300',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Clutches totales', 'value' => $totalClutches, 'color' => 'text-cyan-300'],
            ],
        ]);
    }

    public function killStreaks(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();

        if ($server) {
            $killRows = Kill::join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('rounds.server_id', $server->id)->where('rounds.gametype', 'sd')
                ->whereIn('kills.match_id', $matchIds)
                ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)
                ->selectRaw("kills.attacker_player_id as player_id, kills.occurred_at, 'kill' as event_type")
                ->get()->all();

            $deathRows = Kill::join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('rounds.server_id', $server->id)->where('rounds.gametype', 'sd')
                ->whereIn('kills.match_id', $matchIds)
                ->whereNotNull('kills.victim_player_id')
                ->selectRaw("kills.victim_player_id as player_id, kills.occurred_at, 'death' as event_type")
                ->get()->all();

            // Plain array_merge (not Eloquent Collection::merge()) — that merges by
            // primary key when `id` isn't selected, which silently collapses rows
            // with no `id` column into one (see the 2026-08 "Horas Jugadas" bug).
            $events = collect(array_merge($killRows, $deathRows))->groupBy('player_id');

            $streaks = [];
            foreach ($events as $playerId => $playerEvents) {
                $current = 0;
                $best = 0;
                foreach ($playerEvents->sortBy('occurred_at') as $e) {
                    if ($e->event_type === 'kill') {
                        $current++;
                        $best = max($best, $current);
                    } else {
                        $current = 0;
                    }
                }
                if ($best > 0) {
                    $streaks[$playerId] = $best;
                }
            }

            arsort($streaks);
            $streaks = array_slice($streaks, 0, 50, true);
            $players = Player::whereIn('id', array_keys($streaks))->get()->keyBy('id');

            $rows = collect($streaks)->map(function ($best, $playerId) use ($players) {
                $player = $players[$playerId] ?? null;

                return $player ? (object) ['player' => $player, 'value' => $best, 'share' => null] : null;
            })->filter()->values();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.streaks-kills', 'icon' => '🔥', 'title' => 'Rachas de Bajas',
            'subtitle' => 'Mejor racha histórica de bajas sin morir (Search and Destroy)',
            'valueLabel' => 'racha', 'valueColor' => 'text-orange-400',
            'shareLabel' => null,
            'statCards' => [],
        ]);
    }

    public function chattiest(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalMessages = 0;

        if ($server) {
            $tally = ChatMessage::where('server_id', $server->id)
                ->whereIn('match_id', $matchIds)
                ->whereNotNull('player_id')
                ->selectRaw('player_id, count(*) as c')
                ->groupBy('player_id')->orderByDesc('c')->limit(50)->get();

            $totalMessages = (int) ChatMessage::where('server_id', $server->id)
                ->whereIn('match_id', $matchIds)->count();

            $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');

            $rows = $tally->map(function ($row) use ($players) {
                $player = $players[$row->player_id] ?? null;

                return $player ? (object) ['player' => $player, 'value' => $row->c, 'share' => null] : null;
            })->filter()->values();
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.chattiest', 'icon' => '💬', 'title' => 'Jugador Más Hablador',
            'subtitle' => 'Más mensajes de chat público enviados',
            'valueLabel' => 'mensajes', 'valueColor' => 'text-lime-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Mensajes totales', 'value' => $totalMessages, 'color' => 'text-lime-400'],
            ],
        ]);
    }

    public function peakTimes(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $byHour = collect();
        $byWeekday = collect();

        if ($server) {
            $hourRows = $this->sdKills($server->id, $matchIds)
                ->selectRaw('hour(kills.occurred_at) as h, count(*) as c')
                ->groupBy('h')->pluck('c', 'h');
            $byHour = collect(range(0, 23))->map(fn ($h) => (object) ['label' => sprintf('%02d:00', $h), 'value' => $hourRows[$h] ?? 0]);

            // DAYOFWEEK: 1=Sunday..7=Saturday — reorder to a Monday-first week for display.
            $weekdayRows = $this->sdKills($server->id, $matchIds)
                ->selectRaw('dayofweek(kills.occurred_at) as d, count(*) as c')
                ->groupBy('d')->pluck('c', 'd');
            $labels = [2 => 'Lun', 3 => 'Mar', 4 => 'Mié', 5 => 'Jue', 6 => 'Vie', 7 => 'Sáb', 1 => 'Dom'];
            $byWeekday = collect($labels)->map(fn ($label, $d) => (object) ['label' => $label, 'value' => $weekdayRows[$d] ?? 0])->values();
        }

        $maxHour = $byHour->max('value') ?: 1;
        $maxWeekday = $byWeekday->max('value') ?: 1;

        return view('specialties.peak-times', compact('servers', 'server', 'seasons', 'seasonId', 'byHour', 'byWeekday', 'maxHour', 'maxWeekday'));
    }

    public function timeouts(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();

        if ($server) {
            $rows = MatchEvent::where('server_id', $server->id)
                ->whereIn('match_id', $matchIds)
                ->where('event_type', 'timeout_call')->whereNotNull('name')
                ->selectRaw('name, side, count(*) as c')
                ->groupBy('name', 'side')->orderByDesc('c')->limit(50)
                ->get();
        }

        return view('specialties.timeouts', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.timeouts', 'icon' => '⏸️', 'title' => 'Timeouts',
            'subtitle' => 'Quién más pide tiempo fuera durante una partida',
            'valueLabel' => 'Timeouts pedidos', 'emptyText' => 'Todavía no hay timeouts registrados.',
        ]);
    }

    /**
     * "Bash" here means an actual melee kill (mod=MOD_MELEE) — the rifle-butt/knife
     * hit players call "bash" in-game. Originally built on the log's BASH_CALL; event
     * instead, which turned out to be a red herring: it's an unrelated, extremely
     * rare directive (seen once in the server's entire history) with no confirmed
     * meaning, while real melee kills happen regularly and were sitting unused in
     * kills.mod the whole time.
     */
    public function bashCalls(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalBashes = 0;

        if ($server) {
            $all = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds));

            $rows = $all->filter(fn ($row) => $row->bash > 0)
                ->map(function ($row) {
                    $row->value = $row->bash;
                    $row->share = null;

                    return $row;
                })
                ->sortByDesc('value')->take(50)->values();

            $totalBashes = $all->sum('bash');
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.bash', 'icon' => '🥊', 'title' => 'Bash',
            'subtitle' => 'Más bajas cuerpo a cuerpo (bash) — Search and Destroy',
            'valueLabel' => 'bash', 'valueColor' => 'text-orange-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Total de bash', 'value' => $totalBashes, 'color' => 'text-orange-400'],
            ],
        ]);
    }

    public function winRate(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $minMaps = 3;

        if ($server) {
            $matches = GameMatch::where('server_id', $server->id)
                ->where('is_backfilled', false)
                ->where('gametype', 'sd')
                ->whereNotNull('ended_at')
                ->whereIn('id', $matchIds)
                ->with('rounds:id,match_id,winner_guids')
                ->get();

            $played = [];
            $won = [];

            foreach ($matches as $match) {
                // "Played" has no direct participant list stored per match — same
                // proxy used by "Racha de Mapas": a player counts as having played a
                // match if they appear as attacker or victim in at least one of its
                // kills.
                $kills = Kill::where('match_id', $match->id)->get(['attacker_guid', 'victim_guid']);
                $participantGuids = $kills->pluck('attacker_guid')->merge($kills->pluck('victim_guid'))
                    ->filter(fn ($g) => $g && $g !== '0')->unique();

                foreach ($participantGuids as $guid) {
                    $played[$guid] = ($played[$guid] ?? 0) + 1;
                }

                $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);
                if ($winningGuids) {
                    foreach ($winningGuids as $guid) {
                        $won[$guid] = ($won[$guid] ?? 0) + 1;
                    }
                }
            }

            $players = Player::whereIn('guid', array_keys($played))->get()->keyBy('guid');

            $rows = collect($played)
                ->map(function ($playedCount, $guid) use ($won, $players, $minMaps) {
                    if ($playedCount < $minMaps) {
                        return null;
                    }
                    $player = $players[$guid] ?? null;
                    if (! $player) {
                        return null;
                    }
                    $wonCount = $won[$guid] ?? 0;

                    return (object) [
                        'player' => $player,
                        'won' => $wonCount,
                        'played' => $playedCount,
                        'rate' => round(min($wonCount, $playedCount) / $playedCount * 100, 1),
                    ];
                })
                ->filter()
                ->sortByDesc('rate')
                ->take(50)
                ->values();
        }

        return view('specialties.win-rate', compact('servers', 'server', 'seasons', 'seasonId', 'rows', 'minMaps'));
    }

    /**
     * Categoriza a los jugadores en rangos A-E segun un score de 70% K/D +
     * 30% win rate — cada metrica se convierte a un percentil (0-100) dentro
     * del pool de jugadores calificados antes de combinarse, para que ambas
     * pesen relativo al resto del server y no a una escala arbitraria. Los
     * rangos son quintiles de ese score (A = top 20%, ..., E = bottom 20%),
     * asi que siempre quedan mas o menos parejos sin importar cuantos
     * jugadores califiquen. Headshots% y granadas% se muestran en la tabla
     * como referencia pero no entran en el score (ver comentario mas abajo).
     */
    public function rango(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $minMatches = PlayerRankCalculator::MIN_MATCHES;
        $minKills = PlayerRankCalculator::MIN_KILLS;

        // Mismo calculo exacto que usa el balanceador de Equipos (/equipos) --
        // unificado el 2026-08-27 para que los dos consumidores no puedan
        // volver a desincronizarse (antes esta pagina duplicaba la logica
        // entera de percentiles/quintiles, ver historial de git antes de este
        // commit si hace falta comparar). ->values() para renumerar 0..n-1,
        // la vista usa el indice del foreach como puesto en el ranking.
        if ($server) {
            $rows = PlayerRankCalculator::calculateForServer($server, $seasonId)->values();
        }

        return view('specialties.rango', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'minMatches' => $minMatches, 'minKills' => $minKills,
        ]);
    }

    public function bombs(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalPlants = 0;
        $totalDefuses = 0;

        if ($server) {
            if ($seasonId === 'all') {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where('bomb_plants', '>', 0)
                    ->whereHas('player')
                    ->orderByDesc('bomb_plants')
                    ->limit(50)
                    ->get()
                    ->map(function ($row) {
                        $row->value = $row->bomb_plants;
                        $row->share = null;

                        return $row;
                    });

                $totals = PlayerServerStat::where('server_id', $server->id)
                    ->selectRaw('sum(bomb_plants) as p, sum(bomb_defuses) as d')->first();
                $totalPlants = (int) ($totals->p ?? 0);
                $totalDefuses = (int) ($totals->d ?? 0);
            } else {
                $serverMatchIds = GameMatch::where('server_id', $server->id)->whereIn('id', $matchIds)->pluck('id');

                $tally = PlayerMatchExtra::whereIn('match_id', $serverMatchIds)
                    ->where('bomb_plants', '>', 0)
                    ->selectRaw('player_id, sum(bomb_plants) as bomb_plants')
                    ->groupBy('player_id')
                    ->orderByDesc('bomb_plants')
                    ->limit(50)
                    ->get();

                $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');
                $killsByPlayer = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds))->keyBy('player.id');

                $rows = $tally->map(function ($row) use ($players, $killsByPlayer) {
                    $player = $players[$row->player_id] ?? null;

                    return $player ? (object) [
                        'player' => $player, 'value' => (int) $row->bomb_plants, 'share' => null,
                        'kills' => $killsByPlayer[$row->player_id]->kills ?? 0,
                    ] : null;
                })->filter()->values();

                $totalPlants = (int) PlayerMatchExtra::whereIn('match_id', $serverMatchIds)->sum('bomb_plants');
                $totalDefuses = (int) PlayerMatchExtra::whereIn('match_id', $serverMatchIds)->sum('bomb_defuses');
            }
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.bombs', 'icon' => '💣', 'title' => 'Especialistas en Bombas',
            'subtitle' => 'Más bombas plantadas (Search and Destroy)',
            'valueLabel' => 'plantadas', 'valueColor' => 'text-red-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Bombas plantadas', 'value' => $totalPlants, 'color' => 'text-red-400'],
                ['label' => 'Bombas desactivadas', 'value' => $totalDefuses, 'color' => 'text-emerald-400'],
            ],
        ]);
    }

    public function damage(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();
        $totalDamage = 0;

        if ($server) {
            if ($seasonId === 'all') {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where('damage_dealt', '>', 0)
                    ->whereHas('player')
                    ->orderByDesc('damage_dealt')
                    ->limit(50)
                    ->get()
                    ->map(function ($row) {
                        $row->value = number_format($row->damage_dealt);
                        $row->share = null;

                        return $row;
                    });

                $totalDamage = (int) PlayerServerStat::where('server_id', $server->id)->sum('damage_dealt');
            } else {
                $serverMatchIds = GameMatch::where('server_id', $server->id)->whereIn('id', $matchIds)->pluck('id');

                $tally = PlayerMatchExtra::whereIn('match_id', $serverMatchIds)
                    ->where('damage_dealt', '>', 0)
                    ->selectRaw('player_id, sum(damage_dealt) as damage_dealt')
                    ->groupBy('player_id')
                    ->orderByDesc('damage_dealt')
                    ->limit(50)
                    ->get();

                $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');
                $killsByPlayer = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds))->keyBy('player.id');

                $rows = $tally->map(function ($row) use ($players, $killsByPlayer) {
                    $player = $players[$row->player_id] ?? null;

                    return $player ? (object) [
                        'player' => $player, 'value' => number_format((int) $row->damage_dealt), 'share' => null,
                        'kills' => $killsByPlayer[$row->player_id]->kills ?? 0,
                    ] : null;
                })->filter()->values();

                $totalDamage = (int) PlayerMatchExtra::whereIn('match_id', $serverMatchIds)->sum('damage_dealt');
            }
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.damage', 'icon' => '💥', 'title' => 'Especialistas en Daño',
            'subtitle' => 'Más daño infligido en total (Search and Destroy)',
            'valueLabel' => 'daño', 'valueColor' => 'text-amber-400',
            'shareLabel' => null,
            'statCards' => [
                ['label' => 'Daño total infligido', 'value' => number_format($totalDamage), 'color' => 'text-amber-400'],
            ],
        ]);
    }

    public function disconnects(Request $request)
    {
        [$servers, $server] = $this->resolveServer($request);
        [$seasons, $seasonId, $matchIds] = $this->resolveSeason($request);

        $rows = collect();

        if ($server) {
            if ($seasonId === 'all') {
                $rows = PlayerServerStat::with('player')
                    ->where('server_id', $server->id)
                    ->where('mid_round_disconnects', '>', 0)
                    ->whereHas('player')
                    ->orderByDesc('mid_round_disconnects')
                    ->limit(50)
                    ->get()
                    ->map(function ($row) {
                        $row->value = $row->mid_round_disconnects;
                        $row->share = null;

                        return $row;
                    });
            } else {
                $serverMatchIds = GameMatch::where('server_id', $server->id)->whereIn('id', $matchIds)->pluck('id');

                $tally = PlayerMatchExtra::whereIn('match_id', $serverMatchIds)
                    ->where('mid_round_disconnects', '>', 0)
                    ->selectRaw('player_id, sum(mid_round_disconnects) as mid_round_disconnects')
                    ->groupBy('player_id')
                    ->orderByDesc('mid_round_disconnects')
                    ->limit(50)
                    ->get();

                $players = Player::whereIn('id', $tally->pluck('player_id'))->get()->keyBy('id');
                $killsByPlayer = KillAggregator::aggregate(fn () => $this->sdKills($server->id, $matchIds))->keyBy('player.id');

                $rows = $tally->map(function ($row) use ($players, $killsByPlayer) {
                    $player = $players[$row->player_id] ?? null;

                    return $player ? (object) [
                        'player' => $player, 'value' => (int) $row->mid_round_disconnects, 'share' => null,
                        'kills' => $killsByPlayer[$row->player_id]->kills ?? 0,
                    ] : null;
                })->filter()->values();
            }
        }

        return view('specialties.ranking', [
            'servers' => $servers, 'server' => $server, 'seasons' => $seasons, 'seasonId' => $seasonId, 'rows' => $rows,
            'routeName' => 'specialties.disconnects', 'icon' => '🔌', 'title' => 'Se Fueron a Media Ronda',
            'subtitle' => 'Desconexiones mientras la ronda seguía activa (Search and Destroy)',
            'valueLabel' => 'desconexiones', 'valueColor' => 'text-rose-400',
            'shareLabel' => null,
            'statCards' => [],
        ]);
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return "{$hours}h {$remaining}min";
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: ?Server} */
    private function resolveServer(Request $request): array
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();

        return [$servers, $server];
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: int|string, 2: \Illuminate\Support\Collection} */
    private function resolveSeason(Request $request): array
    {
        $seasons = Season::orderByDesc('started_at')->get();
        $seasonParam = $request->query('season');
        $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        return [$seasons, $seasonId, $matchIds];
    }

    /**
     * Base query for "real" kills on a server: Search & Destroy only (same rule as the
     * main ranking), joined to rounds, suicides excluded.
     */
    private function sdKills(int $serverId, $matchIds)
    {
        return Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.server_id', $serverId)
            ->where('rounds.gametype', 'sd')
            ->where('kills.is_suicide', false)
            ->whereIn('kills.match_id', $matchIds);
    }
}
