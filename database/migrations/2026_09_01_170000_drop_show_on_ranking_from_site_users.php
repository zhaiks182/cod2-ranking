<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sacado (2026-09-01) a pedido del dueño: "todos los usuarios deben
        // verse los perfiles" -- ya no hay forma de ocultarse del ranking.
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn('show_on_ranking');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->boolean('show_on_ranking')->default(true)->after('website_url');
        });
    }
};
