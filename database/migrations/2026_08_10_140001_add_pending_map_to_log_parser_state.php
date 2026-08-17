<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matches/rounds are now only created on RoundStart;, not on every InitGame:
        // (ready-up lobby cycles were spamming empty matches) — but the map/gametype are
        // only known from the InitGame: line, so they have to be remembered here between
        // cron runs until the RoundStart; that confirms the round actually began.
        Schema::table('log_parser_state', function (Blueprint $table) {
            $table->string('pending_map')->nullable()->after('current_match_id');
            $table->string('pending_gametype')->nullable()->after('pending_map');
        });
    }

    public function down(): void
    {
        Schema::table('log_parser_state', function (Blueprint $table) {
            $table->dropColumn(['pending_map', 'pending_gametype']);
        });
    }
};
