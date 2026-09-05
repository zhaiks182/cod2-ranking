<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos que Discord YA venia entregando en cada login y se descartaban
        // (2026-09-05, a pedido del dueño). Los scopes pedidos por
        // socialiteproviders/discord son `identify` + `email` desde siempre, asi
        // que esto NO cambia el consentimiento: nadie tiene que volver a
        // autorizar nada, solo se guarda lo que ya llegaba en el payload de
        // /users/@me y se tiraba en SiteAuthController::callback().
        Schema::table('site_users', function (Blueprint $table) {
            // Nombre para mostrar de Discord -- distinto del `username` (handle
            // unico). Discord muestra este en la UI desde que elimino los
            // discriminadores #1234, asi que es el que el jugador reconoce.
            $table->string('discord_global_name')->nullable()->after('discord_username');

            $table->string('discord_email')->nullable()->after('discord_global_name');

            // `verified` de Discord: si el mail no esta verificado ahi, no sirve
            // como via de contacto real -- por eso se guarda el flag y no solo
            // la direccion.
            $table->boolean('discord_email_verified')->nullable()->after('discord_email');

            // Idioma del cliente de Discord (ej. "es-ES", "en-US"). Se guarda
            // crudo; la columna `language` del perfil (que solo acepta es/en,
            // ver SetLocale::SUPPORTED) se autocompleta a partir de esto en
            // SiteAuthController, sin pisar nunca lo que el jugador haya elegido
            // a mano en /mi-cuenta.
            $table->string('discord_locale', 10)->nullable()->after('discord_email_verified');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn([
                'discord_global_name', 'discord_email', 'discord_email_verified', 'discord_locale',
            ]);
        });
    }
};
