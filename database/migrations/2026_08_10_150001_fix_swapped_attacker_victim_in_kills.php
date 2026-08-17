<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The parser had attacker/victim swapped in every Kill; line read so far (confirmed
     * against two real matches' final in-game scoreboards) — this corrects every existing
     * row in `kills` and then rebuilds every derived counter (players.*_total,
     * player_map_stats, player_server_stats) from the corrected data, since those were
     * incremented in the wrong direction the whole time.
     */
    public function up(): void
    {
        DB::table('kills')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('kills')->where('id', $row->id)->update([
                    'attacker_player_id' => $row->victim_player_id,
                    'attacker_guid' => $row->victim_guid,
                    'attacker_name' => $row->victim_name,
                    'victim_player_id' => $row->attacker_player_id,
                    'victim_guid' => $row->attacker_guid,
                    'victim_name' => $row->attacker_name,
                ]);
            }
        });

        DB::table('players')->update([
            'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0,
            'grenade_kills_total' => 0, 'suicides_total' => 0,
        ]);
        DB::table('player_map_stats')->update(['kills' => 0, 'deaths' => 0, 'headshots' => 0, 'grenade_kills' => 0]);
        DB::table('player_server_stats')->update(['kills' => 0, 'deaths' => 0, 'headshots' => 0, 'grenade_kills' => 0, 'suicides' => 0]);

        $kills = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->select('kills.*', 'rounds.map', 'rounds.server_id')
            ->get();

        foreach ($kills as $kill) {
            if ($kill->victim_player_id) {
                DB::table('players')->where('id', $kill->victim_player_id)->increment('deaths_total');
                if ($kill->is_suicide) {
                    DB::table('players')->where('id', $kill->victim_player_id)->increment('suicides_total');
                }
                $this->bump('player_map_stats', ['player_id' => $kill->victim_player_id, 'server_id' => $kill->server_id, 'map' => $kill->map], ['deaths' => 1]);
                $this->bump('player_server_stats', ['player_id' => $kill->victim_player_id, 'server_id' => $kill->server_id], ['deaths' => 1]);
            }

            if ($kill->attacker_player_id && ! $kill->is_suicide) {
                DB::table('players')->where('id', $kill->attacker_player_id)->increment('kills_total');
                if ($kill->is_headshot) {
                    DB::table('players')->where('id', $kill->attacker_player_id)->increment('headshots_total');
                }
                if ($kill->is_grenade) {
                    DB::table('players')->where('id', $kill->attacker_player_id)->increment('grenade_kills_total');
                }
                $this->bump('player_map_stats', ['player_id' => $kill->attacker_player_id, 'server_id' => $kill->server_id, 'map' => $kill->map], [
                    'kills' => 1, 'headshots' => $kill->is_headshot ? 1 : 0, 'grenade_kills' => $kill->is_grenade ? 1 : 0,
                ]);
                $this->bump('player_server_stats', ['player_id' => $kill->attacker_player_id, 'server_id' => $kill->server_id], [
                    'kills' => 1, 'headshots' => $kill->is_headshot ? 1 : 0, 'grenade_kills' => $kill->is_grenade ? 1 : 0,
                ]);
            }
        }
    }

    private function bump(string $table, array $match, array $increments): void
    {
        $exists = DB::table($table)->where($match)->exists();

        if (! $exists) {
            DB::table($table)->insert(array_merge($match, $increments, ['created_at' => now(), 'updated_at' => now()]));

            return;
        }

        foreach ($increments as $column => $amount) {
            if ($amount > 0) {
                DB::table($table)->where($match)->increment($column, $amount);
            }
        }
    }

    public function down(): void
    {
        // Not reversible (the "fix" and "un-fix" are the same swap operation, but running
        // it again would require the same recomputation) — restore from a backup instead.
    }
};
