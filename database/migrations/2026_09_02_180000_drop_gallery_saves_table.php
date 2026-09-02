<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Guardar" (tipo "Ver más tarde" de YouTube) sacado a pedido del
        // dueño (2026-09-02) el mismo día que se agregó -- reemplazado por
        // "Descargar" en su lugar. Ver la migración que la creaba
        // (2026_09_02_160000_create_gallery_saves_table) para el esquema
        // original, por si se retoma la idea en el futuro.
        Schema::dropIfExists('gallery_saves');
    }

    public function down(): void
    {
        Schema::create('gallery_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_item_id')->constrained('gallery_items')->cascadeOnDelete();
            $table->foreignId('site_user_id')->constrained('site_users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['gallery_item_id', 'site_user_id']);
        });
    }
};
