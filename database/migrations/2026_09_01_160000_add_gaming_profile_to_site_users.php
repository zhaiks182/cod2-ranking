<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modulo de perfil "gaming" (2026-09-01, a pedido del dueño, inspirado en
        // una referencia visual de otro sitio) -- todo autodeclarado por el
        // jugador, nada de esto reemplaza datos reales calculados de sus partidas
        // (arma/mapa favorito real siguen viniendo de KillAggregator, no de aca).
        Schema::table('site_users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('discord_avatar_url');
            $table->string('clan_tag', 20)->nullable()->after('role');
            $table->string('country', 2)->nullable()->after('clan_tag');
            $table->string('language', 5)->nullable()->after('country');
            $table->string('preferred_role', 40)->nullable()->after('language');
            $table->string('youtube_url')->nullable()->after('instagram_url');
            $table->string('twitter_url')->nullable()->after('youtube_url');
            $table->string('website_url')->nullable()->after('twitter_url');

            // Preferencia real (no cosmetica) -- filtra al jugador de /ranking y del
            // top de la home cuando esta en false. default true: nadie queda oculto
            // de sorpresa por una migracion, el jugador tiene que optar activamente.
            $table->boolean('show_on_ranking')->default(true)->after('website_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_path', 'clan_tag', 'country', 'language', 'preferred_role',
                'youtube_url', 'twitter_url', 'website_url', 'show_on_ranking',
            ]);
        });
    }
};
