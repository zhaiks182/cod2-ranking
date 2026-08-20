<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('systemd_service')->nullable()->after('log_path');
        });

        // Backfill el servidor existente -- unico server activo hoy.
        DB::table('servers')->where('slug', 'pug-latam')->update(['systemd_service' => 'cod2server.service']);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('systemd_service');
        });
    }
};
