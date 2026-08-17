<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // event_type: halftime, overtime, match_end, timeout_call, timeout_cancel, bash_call.
        // side/name are only populated for the side-attributed events (timeout_call,
        // timeout_cancel, bash_call) — halftime/overtime/match_end are server-wide
        // markers with no associated player.
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('round_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('side', 10)->nullable();
            $table->string('name')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['match_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
