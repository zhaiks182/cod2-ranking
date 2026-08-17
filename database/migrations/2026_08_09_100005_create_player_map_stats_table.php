<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_map_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('map')->index();
            $table->unsignedInteger('kills')->default(0);
            $table->unsignedInteger('deaths')->default(0);
            $table->unsignedInteger('headshots')->default(0);
            $table->unsignedInteger('grenade_kills')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'map']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_map_stats');
    }
};
