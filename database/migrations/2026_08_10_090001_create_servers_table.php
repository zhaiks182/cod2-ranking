<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('log_path');
            $table->string('rcon_host')->default('127.0.0.1');
            $table->unsignedInteger('rcon_port');
            $table->text('rcon_password');
            $table->string('connect_ip');
            $table->unsignedInteger('connect_port');
            $table->unsignedInteger('max_clients')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
