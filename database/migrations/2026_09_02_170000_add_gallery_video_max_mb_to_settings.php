<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tope de tamaño solo para video (2026-09-02, a pedido del dueño) --
        // 30MB por default, APARTE de la cuota total (gallery_quota_mb).
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('gallery_video_max_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('gallery_video_max_mb');
        });
    }
};
