<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reemplazado por un clan real (2026-09-03, ver
        // docs/superpowers/specs/2026-09-03-clanes-design.md) -- el tag de
        // texto libre sin validar deja de tener sentido como fuente de
        // verdad separada.
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn('clan_tag');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->string('clan_tag', 20)->nullable()->after('role');
        });
    }
};
