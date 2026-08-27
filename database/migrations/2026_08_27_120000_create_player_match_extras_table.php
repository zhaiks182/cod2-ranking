<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por (jugador, partida) para Bomb;/Damage;/Disconnected; -- poblada por
 * ParseCod2Log::bumpServerStatExtra() en paralelo al acumulador plano existente
 * (player_server_stats, que no se toca). La temporada de cualquier fila se deriva
 * por join a matches.season_id, sin columna propia -- mismo criterio que ya usan
 * rounds/kills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_match_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedInteger('bomb_plants')->default(0);
            $table->unsignedInteger('bomb_defuses')->default(0);
            $table->unsignedInteger('damage_dealt')->default(0);
            $table->unsignedInteger('damage_taken')->default(0);
            $table->unsignedInteger('mid_round_disconnects')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_match_extras');
    }
};
