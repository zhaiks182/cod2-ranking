<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Nullable -- null significa "todavia no se le mando (o intento
            // mandar) el resultado a Discord". Se pone en now() apenas se postea
            // con exito, para que cod2:notify-discord-matches (cron cada minuto)
            // no vuelva a postear la misma partida dos veces.
            $table->timestamp('discord_notified_at')->nullable()->after('season_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('discord_notified_at');
        });
    }
};
