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
            $table->text('welcome_message')->nullable()->after('max_clients');
        });

        DB::table('servers')->whereNull('welcome_message')->update([
            'welcome_message' => '^2Bienvenido al servidor: ^7{name}',
        ]);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('welcome_message');
        });
    }
};
