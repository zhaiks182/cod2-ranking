<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Miniatura real de video (2026-09-02, a pedido del dueño -- para
        // que el link compartido en Discord/redes muestre una imagen real,
        // no el logo generico del sitio). Un solo frame extraido con ffmpeg
        // al momento de subir, nunca se genera para imagenes (esas ya usan
        // el archivo original como og:image).
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
