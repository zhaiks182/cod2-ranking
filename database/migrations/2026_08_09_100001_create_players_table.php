<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bots always report guid=0 (no HWID), so they're never stored here — see
        // Player::firstOrCreateFromLog(), which returns null for guid=0 and leaves
        // kills.attacker_player_id/victim_player_id null instead of collapsing all
        // bots into one fake row or fighting MySQL over a conditional unique index.
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->integer('guid')->unique();
            $table->string('last_name');
            $table->string('last_name_plain');
            $table->unsignedInteger('kills_total')->default(0);
            $table->unsignedInteger('deaths_total')->default(0);
            $table->unsignedInteger('headshots_total')->default(0);
            $table->unsignedInteger('grenade_kills_total')->default(0);
            $table->unsignedInteger('suicides_total')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
