<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Pool de mapas que entra al veto. El pool completo del sitio son 15
            // (MapCatalog::pickerOptions()), demasiados para banear de a uno: el
            // admin elige aca los que realmente se juegan.
            $table->json('pug_veto_pool')->nullable()->after('gallery_video_max_mb');

            // Cuantos mapas quedan al final del veto (los que se juegan esa noche).
            // Junto con el tamaño del pool define cuantos baneos hace cada capitan:
            // (pool - este numero) tiene que dar par, si no uno banea mas que el otro.
            $table->unsignedTinyInteger('pug_maps_count')->default(3)->after('pug_veto_pool');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['pug_veto_pool', 'pug_maps_count']);
        });
    }
};
