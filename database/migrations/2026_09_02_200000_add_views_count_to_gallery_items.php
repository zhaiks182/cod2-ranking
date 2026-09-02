<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contador de reproducciones (2026-09-02, a pedido del dueño) --
        // solo aplica a video, se incrementa cuando el <video> realmente
        // arranca a reproducirse (evento "play"), no solo al visitar la
        // pagina del item.
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->unsignedInteger('views_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->dropColumn('views_count');
        });
    }
};
