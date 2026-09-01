<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Insignia de comunidad (2026-09-01, a pedido del dueño) -- texto libre
        // que un admin carga desde /adm_cod2/jugadores/cuentas-discord (ej.
        // "Staff", "VIP", "Fundador"), mostrada como badge en el perfil publico
        // del jugador. A proposito NO es lo mismo que User::MODULES/permissions
        // del panel admin -- esto es solo cosmetico, nunca otorga acceso a
        // /adm_cod2 (decision explicita, ver conversacion del 2026-09-01).
        Schema::table('site_users', function (Blueprint $table) {
            $table->string('role', 40)->nullable()->after('discord_avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
