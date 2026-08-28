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

        // "Días jugados" para el ranking (pedido de un jugador, 2026-08-28: "un
        // apartado con el numero de veces que se conecto, 1 por dia, para
        // promediar con las kills y dar un ranking mas justo"). Se calcula desde
        // kills.occurred_at (dias con al menos un kill O una muerte) en vez de
        // llevar un registro nuevo de conexiones: no hay ninguna tabla que guarde
        // cada Connected; como evento propio (solo se usa para actualizar
        // last_seen_at al vuelo), asi que un tracker nuevo solo tendria datos
        // desde que se agregara -- esto en cambio funciona retroactivo con todo
        // el historial ya cargado, y de paso ya excluye reconexiones solas (un
        // dia sin ningun kill/muerte no suma, pero eso tampoco cambiaria el
        // promedio de kills/dia de nadie). attacker/victim se piden por separado
        // (DATE() no es lo mismo en una UNION de SQL portable entre SQLite/MySQL)
        // y se mergean en PHP con un Set para no contar dos veces un dia en el
        // que el jugador mato Y murio.
        $attackerDays = $baseQuery()->whereNotNull('kills.attacker_player_id')
            ->selectRaw('kills.attacker_player_id as player_id, DATE(kills.occurred_at) as day')
            ->groupBy('kills.attacker_player_id', 'day')->get();
        $victimDays = $baseQuery()->whereNotNull('kills.victim_player_id')
            ->selectRaw('kills.victim_player_id as player_id, DATE(kills.occurred_at) as day')
            ->groupBy('kills.victim_player_id', 'day')->get();
        // concat(), not merge(): these come from selectRaw() without an `id` column, so
        // every row is an Eloquent model with getKey()===null -- Eloquent\Collection's
        // merge() dedupes BY PRIMARY KEY, and with every row sharing the same (null)
        // key, it collapses the two lists down to a single row instead of combining
        // them. concat() just appends both lists, which is what's actually wanted here.
        $daysPlayed = $attackerDays->concat($victimDays)
            ->groupBy('player_id')
            ->map(fn ($rows) => $rows->pluck('day')->unique()->count());

        $ids = $kills->keys()->merge($deaths->keys())->unique();
        $players = Player::whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(function ($id) use ($kills, $deaths, $daysPlayed, $players) {
            $k = $kills->get($id);
            $d = $deaths->get($id);
            $days = (int) ($daysPlayed->get($id) ?? 0);
            $killsCount = (int) ($k->kills ?? 0);

            return (object) [
                'player' => $players[$id],
                'kills' => $killsCount,
                'headshots' => (int) ($k->headshots ?? 0),
                'grenade_kills' => (int) ($k->grenade_kills ?? 0),
                'teamkills' => (int) ($k->teamkills ?? 0),
                'bash' => (int) ($k->bash ?? 0),
                'deaths' => (int) ($d->deaths ?? 0),
                'days_played' => $days,
                'kills_per_day' => $days > 0 ? round($killsCount / $days, 1) : 0.0,
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

    /**
     * Un item por mapa (codigo CRUDO, sin fusionar variantes -- mismo criterio que ya
     * usaba mapKings() con PlayerMapStat, a proposito: mp_dawnville_fix y
     * mp_dawnville_sun quedan separados aca), con el total de kills de ese mapa y el
     * jugador con mas kills en el.
     *
     * @param  Closure(): \Illuminate\Database\Eloquent\Builder  $baseQuery  Query de
     *      Kill ya scopeada (server/season/gametype/etc), SIN filtrar por mapa todavia.
     */
    public static function topByMap(Closure $baseQuery): Collection
    {
        $totals = $baseQuery()
            ->selectRaw('rounds.map as map, count(*) as uses')
            ->groupBy('rounds.map')
            ->orderByDesc('uses')
            ->get();

        $byPlayer = $baseQuery()
            ->whereNotNull('kills.attacker_player_id')
            ->selectRaw('rounds.map as map, kills.attacker_player_id, count(*) as kills')
            ->groupBy('rounds.map', 'kills.attacker_player_id')
            ->get()
            ->groupBy('map')
            ->map(fn ($rows) => $rows->sortByDesc('kills')->first());

        // Muertes del jugador top en ese mapa especifico -- segunda pasada chica, solo
        // para los (mapa, jugador) que ya salieron ganadores arriba.
        $deathsByPair = $baseQuery()
            ->whereNotNull('kills.victim_player_id')
            ->selectRaw('rounds.map as map, kills.victim_player_id as attacker_player_id, count(*) as deaths')
            ->groupBy('rounds.map', 'kills.victim_player_id')
            ->get()
            ->keyBy(fn ($r) => $r->map.'|'.$r->attacker_player_id);

        $playerIds = $byPlayer->pluck('attacker_player_id')->filter()->unique();
        $players = Player::whereIn('id', $playerIds)->get()->keyBy('id');

        return $totals->map(function ($row) use ($byPlayer, $deathsByPair, $players) {
            $top = $byPlayer->get($row->map);
            $topPlayer = $top ? ($players[$top->attacker_player_id] ?? null) : null;
            $topDeaths = $top ? ($deathsByPair->get($row->map.'|'.$top->attacker_player_id)->deaths ?? 0) : 0;

            return (object) [
                'map' => $row->map,
                'uses' => (int) $row->uses,
                'topPlayer' => $topPlayer,
                'topKills' => (int) ($top->kills ?? 0),
                'topDeaths' => (int) $topDeaths,
            ];
        })->filter(fn ($m) => $m->topPlayer && $m->uses > 0)->values();
    }
}
