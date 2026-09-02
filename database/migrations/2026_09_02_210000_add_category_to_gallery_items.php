<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categoria de contenido (2026-09-02, a pedido del dueño) -- ver
        // App\Support\GalleryCategory::OPTIONS para la lista de codigos
        // validos. Opcional, elegida por el usuario al subir.
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->string('category')->nullable()->after('match_id');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
