<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kills', function (Blueprint $table) {
            $table->boolean('is_teamkill')->default(false)->after('is_suicide');
        });
    }

    public function down(): void
    {
        Schema::table('kills', function (Blueprint $table) {
            $table->dropColumn('is_teamkill');
        });
    }
};
