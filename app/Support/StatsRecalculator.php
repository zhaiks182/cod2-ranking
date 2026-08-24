<?php

namespace App\Support;

use App\Models\GameMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatsRecalculator
{
    /**
     * Rebuilds players.*_total, player_map_stats and player_server_stats from the kills
     * table ground truth. Used after deleting a match (or any other operation that
     * removes kills directly) since those cached aggregates aren't tied by foreign key
     * to the rows that fed them and won't adjust themselves.
     *
     * Zeroes only the kill-derived columns on existing rows — NOT a delete()+rebuild
     * of the whole row. player_server_stats/player_map_stats also carry damage_dealt,
     * damage_taken, bomb_plants, bomb_defuses and mid_round_disconnects, which are
     * pure running counters bumped directly off Damage;/Bomb;/Disconnected; log lines
     * (see ParseCod2Log) with no detail table backing them — unlike kills, there's
     * nothing to recompute them FROM, so wiping the row destroys that data
     * permanently. Confirmed this happened for real: a match deletion zeroed damage/
     * bomb stats server-wide when this used delete()+rebuild.
     *
     * Also runs on a schedule (see routes/console.php, RecalculateStats command) — not
     * just after admin actions. ParseCod2Log::recordKill() bumps these same columns
     * live, kill-by-kill, before a match's outcome is known (a match only resolves as
     * "abandoned without a real conclusion" once it ends without reaching 13 rounds or
     * MatchEnd; — see GameMatch::scopeAbandonedWithoutConclusion()), so the periodic
     * rebuild is what retroactively removes an abandoned match's kills from the
     * ranking once that becomes known, the same way it already retroactively repairs
     * things after a manual match deletion.
     */
    public static function recalculateAll(): void
    {
        DB::transaction(function () {
            DB::table('players')->update([
                'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0,
                'grenade_kills_total' => 0, 'suicides_total' => 0,
            ]);
            DB::table('player_map_stats')->update([
                'kills' => 0, 'deaths' => 0, 'headshots' => 0, 'grenade_kills' => 0, 'teamkills' => 0,
            ]);
            DB::table('player_server_stats')->update([
                'kills' => 0, 'deaths' => 0, 'headshots' => 0, 'grenade_kills' => 0, 'teamkills' => 0, 'suicides' => 0,
            ]);

            $excludedMatchIds = GameMatch::abandonedWithoutConclusion()->pluck('id');

            self::applyPlayerTotals($excludedMatchIds);
            self::applyMapStats($excludedMatchIds);
            self::applyServerStats($excludedMatchIds);
        });
    }

    private static function applyPlayerTotals(Collection $excludedMatchIds): void
    {
        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)->where('rounds.gametype', 'sd')
            ->whereNotIn('rounds.match_id', $excludedMatchIds)
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
            ->whereNotIn('rounds.match_id', $excludedMatchIds)
            ->selectRaw('kills.victim_player_id as player_id, count(*) as deaths, sum(kills.is_suicide) as suicides')
            ->groupBy('kills.victim_player_id')->get();

        foreach ($deaths as $row) {
            DB::table('players')->where('id', $row->player_id)->update([
                'deaths_total' => $row->deaths,
                'suicides_total' => $row->suicides,
            ]);
        }
    }

    private static function applyMapStats(Collection $excludedMatchIds): void
    {
        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)->where('rounds.gametype', 'sd')
            ->whereNotIn('rounds.match_id', $excludedMatchIds)
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
            ->whereNotIn('rounds.match_id', $excludedMatchIds)
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

    private static function applyServerStats(Collection $excludedMatchIds): void
    {
        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->whereNotNull('kills.attacker_player_id')->where('kills.is_suicide', false)->where('rounds.gametype', 'sd')
            ->whereNotIn('rounds.match_id', $excludedMatchIds)
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
            ->whereNotIn('rounds.match_id', $excludedMatchIds)
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
