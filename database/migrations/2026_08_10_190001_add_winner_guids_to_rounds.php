<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            // JSON list of the winning roster's player guids for that round (from the
            // log's "Winners;<side>;<guid>;<name>;..." line). Stored as a roster, not a
            // side (axis/allies) label, because sides swap at halftime but the roster
            // doesn't — this lets a match's final score be computed by grouping rounds
            // by which *roster* won, without needing to track the halftime swap.
            $table->json('winner_guids')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropColumn('winner_guids');
        });
    }
};
