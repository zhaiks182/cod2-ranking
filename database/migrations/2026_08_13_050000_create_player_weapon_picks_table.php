<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Counts weapon pickups/switches (Weapon; log lines), not kills — a different
        // signal from the existing kills-per-weapon ranking: "what they reach for",
        // not "what they're deadly with".
        Schema::create('player_weapon_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('weapon');
            $table->unsignedInteger('picks')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'weapon']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_weapon_picks');
    }
};
