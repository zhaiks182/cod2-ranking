<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_plain');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['player_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_aliases');
    }
};
