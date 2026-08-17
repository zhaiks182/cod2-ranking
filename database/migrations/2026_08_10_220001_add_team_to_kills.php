<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kills', function (Blueprint $table) {
            // The raw axis/allies side each player was on for this specific kill —
            // sides swap at halftime, so this is only meaningful per-kill/per-round,
            // not as a stable "team" for the whole match (see rounds.winner_guids for
            // the roster-based, halftime-proof grouping used for the final score).
            $table->string('attacker_team', 20)->nullable()->after('attacker_name');
            $table->string('victim_team', 20)->nullable()->after('victim_name');
        });
    }

    public function down(): void
    {
        Schema::table('kills', function (Blueprint $table) {
            $table->dropColumn(['attacker_team', 'victim_team']);
        });
    }
};
