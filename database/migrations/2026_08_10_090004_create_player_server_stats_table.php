<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_server_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('kills')->default(0);
            $table->unsignedInteger('deaths')->default(0);
            $table->unsignedInteger('headshots')->default(0);
            $table->unsignedInteger('grenade_kills')->default(0);
            $table->unsignedInteger('suicides')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_server_stats');
    }
};
