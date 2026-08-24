<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Nullable a proposito -- null significa "usar el default de
            // config/hosted_servers.php (HOSTED_SERVERS_MAX_CONCURRENT en .env)",
            // solo pisa ese default cuando el admin lo edita desde el panel
            // (ver Setting::maxConcurrent()).
            $table->unsignedInteger('hosted_servers_max_concurrent')->nullable()->after('discord_benefits');
        });

        // Semilla con el valor actual de .env, para que el deploy de esta
        // migracion no cambie nada visible hasta que el admin lo edite desde
        // adm_cod2/servers -- mismo patron que la migracion de campos de Discord.
        DB::table('settings')->where('id', 1)->update([
            'hosted_servers_max_concurrent' => (int) config('hosted_servers.max_concurrent'),
        ]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('hosted_servers_max_concurrent');
        });
    }
};
