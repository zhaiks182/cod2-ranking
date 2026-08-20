<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two raw names that only differ by a trailing/stray color code (e.g. one has a
        // "^7" reset the other doesn't) render identically but were treated as separate
        // aliases, since the old unique key was (player_id, name) — collapse those into
        // one row per (player_id, name_plain), keeping whichever was seen most recently.
        $groups = DB::table('player_aliases')
            ->select('player_id', 'name_plain')
            ->groupBy('player_id', 'name_plain')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('player_aliases')
                ->where('player_id', $group->player_id)
                ->where('name_plain', $group->name_plain)
                ->orderByDesc('last_seen_at')
                ->get();

            $keep = $rows->first();
            DB::table('player_aliases')
                ->whereIn('id', $rows->skip(1)->pluck('id'))
                ->delete();
        }

        Schema::table('player_aliases', function (Blueprint $table) {
            // Add the replacement index before dropping (player_id, name): that old
            // unique is what backs the player_id foreign key, and MariaDB refuses to
            // drop it (error 1553) if it would leave the FK without a covering index.
            $table->unique(['player_id', 'name_plain']);
            $table->dropUnique(['player_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('player_aliases', function (Blueprint $table) {
            $table->unique(['player_id', 'name']);
            $table->dropUnique(['player_id', 'name_plain']);
        });
    }
};
