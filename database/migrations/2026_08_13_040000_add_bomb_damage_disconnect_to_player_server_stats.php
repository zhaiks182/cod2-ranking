<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_server_stats', function (Blueprint $table) {
            $table->unsignedInteger('bomb_plants')->default(0);
            $table->unsignedInteger('bomb_defuses')->default(0);
            $table->unsignedBigInteger('damage_dealt')->default(0);
            $table->unsignedBigInteger('damage_taken')->default(0);
            $table->unsignedInteger('mid_round_disconnects')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('player_server_stats', function (Blueprint $table) {
            $table->dropColumn(['bomb_plants', 'bomb_defuses', 'damage_dealt', 'damage_taken', 'mid_round_disconnects']);
        });
    }
};
