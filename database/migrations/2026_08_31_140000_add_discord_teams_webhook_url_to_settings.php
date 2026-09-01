<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Separado a proposito de discord_match_webhook_url -- el anuncio de
            // "equipos armados" (DiscordTeamsNotifier, boton en /equipos) puede ir
            // a un canal distinto al de resultados de partida.
            $table->string('discord_teams_webhook_url')->nullable()->after('discord_match_webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('discord_teams_webhook_url');
        });
    }
};
