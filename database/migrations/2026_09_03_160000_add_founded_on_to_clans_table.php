<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El clan real puede ser mucho mas viejo que su registro en el sitio
        // (2026-09-03, a pedido del dueño) -- created_at ya no alcanza como
        // "fecha de fundacion" publica, el fundador la elige a mano. Los
        // clanes ya creados se backfillean con su created_at (mejor valor
        // disponible) para no dejarlos sin fecha.
        Schema::table('clans', function (Blueprint $table) {
            $table->date('founded_on')->nullable()->after('description');
        });

        DB::table('clans')->whereNull('founded_on')->update(['founded_on' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('clans', function (Blueprint $table) {
            $table->dropColumn('founded_on');
        });
    }
};
