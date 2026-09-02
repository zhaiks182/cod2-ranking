<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Galeria multimedia (2026-09-02) -- videos/imagenes subidos por
        // cualquier cuenta con sesion de Discord (site_users), sin requerir
        // perfil de jugador reclamado. Ver docs/superpowers/specs/
        // 2026-09-02-galeria-multimedia-design.md.
        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_user_id')->constrained('site_users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('type'); // image|video, derivado del mimetype al subir
            $table->string('file_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_items');
    }
};
