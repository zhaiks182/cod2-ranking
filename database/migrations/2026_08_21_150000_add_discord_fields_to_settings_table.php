<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('discord_guild_id')->nullable()->after('demo_retention_days');
            $table->string('discord_invite_url')->nullable()->after('discord_guild_id');
            $table->text('discord_description')->nullable()->after('discord_invite_url');
            // Un item de beneficio por linea -- ver DiscordBenefits::parse(). Texto
            // libre en vez de una tabla aparte porque el admin solo necesita
            // reordenar/editar texto, no adjuntar nada mas por item.
            $table->text('discord_benefits')->nullable()->after('discord_description');
        });

        // Semilla con lo que hoy esta hardcodeado en el Blade y en .env, para que el
        // deploy de esta migracion no cambie nada visible hasta que el admin edite
        // los campos nuevos desde adm_cod2/discord.
        DB::table('settings')->where('id', 1)->update([
            'discord_guild_id' => env('DISCORD_GUILD_ID'),
            'discord_invite_url' => env('DISCORD_INVITE_URL'),
            'discord_description' => 'Chateá con la comunidad, reportá jugadores, y enterate de novedades del server en vivo.',
            'discord_benefits' => implode("\n", [
                'Alertas de partidas y anuncios del server',
                'Reportá tramposos y pedí soporte rápido',
                'Coordiná PUGs y partidas de Search & Destroy',
                'Seguí tu ranking y estadísticas en vivo acá en el sitio',
                'Descargá demos de las partidas jugadas',
                'Descubrí rivalidades, rachas y récords del server',
                'Consultá de qué país es cada jugador',
                'Mirá el horario pico para coordinar tus partidas',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['discord_guild_id', 'discord_invite_url', 'discord_description', 'discord_benefits']);
        });
    }
};
