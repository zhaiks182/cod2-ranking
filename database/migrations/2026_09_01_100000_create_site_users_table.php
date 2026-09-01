<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuentas publicas del sitio (login con Discord, 2026-09-01) --
        // completamente separadas de `users` (solo panel admin). Ver
        // docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
        Schema::create('site_users', function (Blueprint $table) {
            $table->id();
            $table->string('discord_id')->unique();
            $table->string('discord_username');
            $table->string('discord_avatar_url')->nullable();

            // Reclamo confirmado -- unico (nullable, MySQL/MariaDB permiten
            // multiples NULL, mismo patron que hosted_servers.port) para que
            // un jugador solo pueda estar reclamado por una cuenta.
            $table->foreignId('player_id')->nullable()->unique()->constrained('players')->nullOnDelete();

            // Reclamo en curso, sin confirmar todavia.
            $table->foreignId('pending_claim_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('claim_code', 20)->nullable();
            $table->timestamp('claim_code_expires_at')->nullable();

            $table->string('bio', 400)->nullable();
            $table->string('steam_url')->nullable();
            $table->string('twitch_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('pc_cpu')->nullable();
            $table->string('pc_gpu')->nullable();
            $table->string('pc_ram')->nullable();
            $table->string('pc_peripherals')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_users');
    }
};
