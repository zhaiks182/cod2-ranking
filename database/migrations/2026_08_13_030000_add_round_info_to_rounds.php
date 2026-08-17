<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            // round_number: zPAM's own "Round N" counter, from RoundInfo; (fires right
            // after RoundStart;). score_after_*: the running score as of the end of
            // THIS round, from Score; (fires right after RoundEnd;/Winners;).
            $table->unsignedInteger('round_number')->nullable()->after('round_label');
            $table->unsignedInteger('score_after_allies')->nullable()->after('round_number');
            $table->unsignedInteger('score_after_axis')->nullable()->after('score_after_allies');
        });
    }

    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropColumn(['round_number', 'score_after_allies', 'score_after_axis']);
        });
    }
};
