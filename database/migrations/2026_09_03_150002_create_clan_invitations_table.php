<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cubre las dos direcciones (solicitud del jugador, invitacion del
        // clan) con una sola tabla -- "direction" decide quien puede
        // aceptar/rechazar cada fila (ver ClanInvitation).
        Schema::create('clan_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_user_id')->constrained('site_users')->cascadeOnDelete();
            $table->foreignId('created_by_site_user_id')->constrained('site_users');
            // string, no enum() -- mismo criterio que hosted_servers.status.
            $table->string('direction', 20);
            $table->string('status', 12)->default('pending');
            $table->timestamps();

            $table->index(['clan_id', 'status']);
            $table->index(['site_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_invitations');
    }
};
