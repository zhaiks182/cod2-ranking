<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Nullable a proposito -- null significa "sin webhook configurado", el
            // comando cod2:notify-discord-matches se salta silenciosamente hasta
            // que el admin lo cargue desde adm_cod2/discord (la URL sale de crear
            // un webhook en la config de un canal de Discord, no se puede generar
            // desde este repo).
            $table->string('discord_match_webhook_url')->nullable()->after('hosted_servers_max_concurrent');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('discord_match_webhook_url');
        });
    }
};
