<?php

namespace App\Support;

use App\Models\Player;
use Closure;
use Illuminate\Support\Collection;

class KillAggregator
{
    /**
     * Builds a kills/deaths/headshots/grenade_kills-per-player leaderboard from a scoped
     * kills query. Kills-as-attacker and deaths-as-victim are two different GROUP BYs on
     * the same table, so they're queried separately and merged here in PHP.
     *
     * @param  Closure(): \Illuminate\Database\Eloquent\Builder  $baseQuery  Returns a
     *      *fresh* already-scoped Kill query builder each call (server/map/date/match
     *      filters, etc.) — query builders are consumed after they execute.
     */
    public static function aggregate(Closure $baseQuery): Collection
    {
        $kills = $baseQuery()->where('kills.is_suicide', false)->whereNotNull('kills.attacker_player_id')
            ->selectRaw('kills.attacker_player_id as player_id, count(*) as kills, sum(kills.is_headshot) as headshots, sum(kills.is_grenade) as grenade_kills, sum(kills.is_teamkill) as teamkills')
            ->groupBy('kills.attacker_player_id')
            ->get()->keyBy('player_id');

        $deaths = $baseQuery()->whereNotNull('kills.victim_player_id')
            ->selectRaw('kills.victim_player_id as player_id, count(*) as deaths')
            ->groupBy('kills.victim_player_id')
            ->get()->keyBy('player_id');

        $ids = $kills->keys()->merge($deaths->keys())->unique();
        $players = Player::whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(function ($id) use ($kills, $deaths, $players) {
            $k = $kills->get($id);
            $d = $deaths->get($id);

            return (object) [
                'player' => $players[$id],
                'kills' => (int) ($k->kills ?? 0),
                'headshots' => (int) ($k->headshots ?? 0),
                'grenade_kills' => (int) ($k->grenade_kills ?? 0),
                'teamkills' => (int) ($k->teamkills ?? 0),
                'deaths' => (int) ($d->deaths ?? 0),
            ];
        })->sortByDesc('kills')->values();
    }
}
