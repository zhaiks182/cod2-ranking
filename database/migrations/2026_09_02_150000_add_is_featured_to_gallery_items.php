<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Video/imagen destacado (2026-09-02, a pedido del dueño) -- solo un
        // admin lo marca desde /adm_cod2/galeria, no el usuario que lo subio.
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('match_id');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_items', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
