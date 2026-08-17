<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_parser_state', function (Blueprint $table) {
            $table->id();
            $table->string('log_path')->unique();
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->foreignId('current_round_id')->nullable()->constrained('rounds')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_parser_state');
    }
};
