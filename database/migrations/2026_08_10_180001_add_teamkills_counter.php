<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_map_stats', function (Blueprint $table) {
            $table->unsignedInteger('teamkills')->default(0)->after('kills');
        });
        Schema::table('player_server_stats', function (Blueprint $table) {
            $table->unsignedInteger('teamkills')->default(0)->after('kills');
        });

        // Backfill from kills already flagged is_teamkill (the column existed before this
        // one, so every row parsed since then already has it set correctly).
        $rows = DB::table('kills')->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.is_teamkill', true)
            ->select('kills.attacker_player_id', 'rounds.server_id', 'rounds.map')
            ->get();

        foreach ($rows as $row) {
            if (! $row->attacker_player_id) {
                continue;
            }

            DB::table('player_map_stats')
                ->where(['player_id' => $row->attacker_player_id, 'server_id' => $row->server_id, 'map' => $row->map])
                ->increment('teamkills');
            DB::table('player_server_stats')
                ->where(['player_id' => $row->attacker_player_id, 'server_id' => $row->server_id])
                ->increment('teamkills');
        }
    }

    public function down(): void
    {
        Schema::table('player_map_stats', function (Blueprint $table) {
            $table->dropColumn('teamkills');
        });
        Schema::table('player_server_stats', function (Blueprint $table) {
            $table->dropColumn('teamkills');
        });
    }
};
