<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only public chat ("say;" in the log) is stored — team chat ("sayteam;") is
        // deliberately excluded (see ParseCod2Log::recordChat()). guid=0 (bots) never
        // send chat in practice, but is guarded against anyway.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('guid');
            $table->string('name');
            $table->text('message');
            $table->timestamp('occurred_at');
            $table->index(['match_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
