<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class StatsRecalculator
{
    /**
     * Rebuilds players.*_total, player_map_stats and player_server_stats from the kills
     * table ground truth. Used after deleting a match (or any other operation that
     * removes kills directly) since those cached aggregates aren't tied by foreign key
     * to the rows that fed them and won't adjust themselves.
     */
    public static function recalculateAll(): void
    {
        DB::transaction(function () {
            DB::table('players')->update([
                'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0,
                'grenade_kills_total' => 0, 'suicides_total' => 0,
            ]);
            DB::table('player_map_stats')->delete();
            DB::table('player_server_stats')->delete();

            self::applyPlayerTotals();
            self::applyMapStats();
            self::applyServerStats();
        });
    }

    private static function applyPlayerTotals(): void
    {
        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)->where('rounds.gametype', 'sd')
            ->selectRaw('kills.attacker_player_id as player_id, count(*) as kills, sum(kills.is_headshot) as headshots, sum(kills.is_grenade) as grenade_kills')
            ->groupBy('kills.attacker_player_id')->get();

        foreach ($kills as $row) {
            DB::table('players')->where('id', $row->player_id)->update([
                'kills_total' => $row->kills,
                'headshots_total' => $row->headshots,
                'grenade_kills_total' => $row->grenade_kills,
            ]);
        }

        $deaths = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.victim_player_id')->where('rounds.gametype', 'sd')
            ->selectRaw('kills.victim_player_id as player_id, count(*) as deaths, sum(kills.is_suicide) as suicides')
            ->groupBy('kills.victim_player_id')->get();

        foreach ($deaths as $row) {
            DB::table('players')->where('id', $row->player_id)->update([
                'deaths_total' => $row->deaths,
                'suicides_total' => $row->suicides,
            ]);
        }
    }

    private static function applyMapStats(): void
    {
        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)->where('rounds.gametype', 'sd')
            ->selectRaw('kills.attacker_player_id as player_id, rounds.server_id, rounds.map, count(*) as kills, sum(kills.is_headshot) as headshots, sum(kills.is_grenade) as grenade_kills, sum(kills.is_teamkill) as teamkills')
            ->groupBy('kills.attacker_player_id', 'rounds.server_id', 'rounds.map')->get();

        foreach ($kills as $row) {
            DB::table('player_map_stats')->updateOrInsert(
                ['player_id' => $row->player_id, 'server_id' => $row->server_id, 'map' => $row->map],
                ['kills' => $row->kills, 'headshots' => $row->headshots, 'grenade_kills' => $row->grenade_kills, 'teamkills' => $row->teamkills]
            );
        }

        $deaths = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.victim_player_id')->where('rounds.gametype', 'sd')
            ->selectRaw('kills.victim_player_id as player_id, rounds.server_id, rounds.map, count(*) as deaths')
            ->groupBy('kills.victim_player_id', 'rounds.server_id', 'rounds.map')->get();

        foreach ($deaths as $row) {
            $exists = DB::table('player_map_stats')->where(['player_id' => $row->player_id, 'server_id' => $row->server_id, 'map' => $row->map])->exists();

            if ($exists) {
                DB::table('player_map_stats')->where(['player_id' => $row->player_id, 'server_id' => $row->server_id, 'map' => $row->map])->update(['deaths' => $row->deaths]);
            } else {
                DB::table('player_map_stats')->insert(['player_id' => $row->player_id, 'server_id' => $row->server_id, 'map' => $row->map, 'deaths' => $row->deaths]);
            }
        }
    }

    private static function applyServerStats(): void
    {
        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)->where('rounds.gametype', 'sd')
            ->selectRaw('kills.attacker_player_id as player_id, rounds.server_id, count(*) as kills, sum(kills.is_headshot) as headshots, sum(kills.is_grenade) as grenade_kills, sum(kills.is_teamkill) as teamkills')
            ->groupBy('kills.attacker_player_id', 'rounds.server_id')->get();

        foreach ($kills as $row) {
            DB::table('player_server_stats')->updateOrInsert(
                ['player_id' => $row->player_id, 'server_id' => $row->server_id],
                ['kills' => $row->kills, 'headshots' => $row->headshots, 'grenade_kills' => $row->grenade_kills, 'teamkills' => $row->teamkills]
            );
        }

        $deaths = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.victim_player_id')->where('rounds.gametype', 'sd')
            ->selectRaw('kills.victim_player_id as player_id, rounds.server_id, count(*) as deaths, sum(kills.is_suicide) as suicides')
            ->groupBy('kills.victim_player_id', 'rounds.server_id')->get();

        foreach ($deaths as $row) {
            $exists = DB::table('player_server_stats')->where(['player_id' => $row->player_id, 'server_id' => $row->server_id])->exists();

            if ($exists) {
                DB::table('player_server_stats')->where(['player_id' => $row->player_id, 'server_id' => $row->server_id])->update(['deaths' => $row->deaths, 'suicides' => $row->suicides]);
            } else {
                DB::table('player_server_stats')->insert(['player_id' => $row->player_id, 'server_id' => $row->server_id, 'deaths' => $row->deaths, 'suicides' => $row->suicides]);
            }
        }
    }
}
