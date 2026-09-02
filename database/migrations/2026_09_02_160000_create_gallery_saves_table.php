<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Guardar" un video/imagen para verlo despues (2026-09-02, a pedido
        // del dueño, tipo "Ver mas tarde" de YouTube) -- mismo esquema que
        // gallery_likes, solo cambia el proposito.
        Schema::create('gallery_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_item_id')->constrained('gallery_items')->cascadeOnDelete();
            $table->foreignId('site_user_id')->constrained('site_users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['gallery_item_id', 'site_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_saves');
    }
};
