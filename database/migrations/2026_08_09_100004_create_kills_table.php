<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();

            // Denormalized guid/name are kept even when *_player_id is null (bots have
            // guid=0 and are never turned into a players row, see players migration).
            $table->foreignId('attacker_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->integer('attacker_guid');
            $table->string('attacker_name');

            $table->foreignId('victim_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->integer('victim_guid');
            $table->string('victim_name');

            $table->string('weapon');
            $table->unsignedInteger('damage');
            $table->string('mod');
            $table->string('hitloc')->nullable();

            $table->boolean('is_headshot')->default(false);
            $table->boolean('is_grenade')->default(false);
            $table->boolean('is_suicide')->default(false);

            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kills');
    }
};
