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
            ->selectRaw("kills.attacker_player_id as player_id, count(*) as kills, sum(kills.is_headshot) as headshots, sum(kills.is_grenade) as grenade_kills, sum(kills.is_teamkill) as teamkills, sum(kills.mod = 'MOD_MELEE') as bash")
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
                'bash' => (int) ($k->bash ?? 0),
                'deaths' => (int) ($d->deaths ?? 0),
            ];
        })->sortByDesc('kills')->values();
    }

    /**
     * Igual proposito que aggregate(), pero agrupado por mapa+servidor para UN
     * jugador especifico, en vez de por jugador para todos -- usado por el perfil
     * de jugador ("Mejores mapas"), donde antes se leia player_map_stats
     * (acumulado de por vida) y ahora se calcula al vuelo scopeado por temporada.
     *
     * @param  Closure(): \Illuminate\Database\Eloquent\Builder  $baseQuery  Query de
     *      Kill ya scopeada (season/gametype/etc), SIN filtrar por jugador todavia.
     */
    public static function aggregateByMap(Closure $baseQuery, int $playerId): Collection
    {
        $kills = $baseQuery()->where('kills.attacker_player_id', $playerId)->where('kills.is_suicide', false)
            ->selectRaw('rounds.map as map, rounds.server_id as server_id, count(*) as kills, sum(kills.is_teamkill) as teamkills')
            ->groupBy('rounds.map', 'rounds.server_id')
            ->get();

        $deaths = $baseQuery()->where('kills.victim_player_id', $playerId)
            ->selectRaw('rounds.map as map, rounds.server_id as server_id, count(*) as deaths')
            ->groupBy('rounds.map', 'rounds.server_id')
            ->get();

        $key = fn ($row) => $row->map.'|'.$row->server_id;
        $killsByKey = $kills->keyBy($key);
        $deathsByKey = $deaths->keyBy($key);

        $allKeys = $killsByKey->keys()->merge($deathsByKey->keys())->unique();
        $serverIds = $allKeys->map(fn ($k) => (int) explode('|', $k)[1])->unique();
        $servers = \App\Models\Server::whereIn('id', $serverIds)->get()->keyBy('id');

        return $allKeys->map(function ($mapKey) use ($killsByKey, $deathsByKey, $servers) {
            [$map, $serverId] = explode('|', $mapKey);
            $k = $killsByKey->get($mapKey);
            $d = $deathsByKey->get($mapKey);

            return (object) [
                'map' => $map,
                'map_codes' => [$map],
                'server' => $servers->get((int) $serverId),
                'kills' => (int) ($k->kills ?? 0),
                'deaths' => (int) ($d->deaths ?? 0),
                'teamkills' => (int) ($k->teamkills ?? 0),
            ];
        })->sortByDesc('kills')->values();
    }
}
