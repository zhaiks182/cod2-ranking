<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla estandar de notificaciones de Laravel (canal `database`) --
        // SiteUser ya usa el trait Notifiable (agregado con el login de
        // Discord), esta es la unica pieza que faltaba para que
        // ->notify()/->notifications/->unreadNotifications funcionen. Nunca
        // se modifica a mano, es el esquema que el propio framework espera.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
