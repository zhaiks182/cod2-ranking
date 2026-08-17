<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A "match" groups the consecutive rounds played on one map in one sitting —
        // a new match starts when the map (or gametype) changes from the previous round.
        // This is what lets stats be browsed "by match and by day" even though a single
        // day of play can span several maps (each its own match).
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('map')->index();
            $table->string('gametype')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
