<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una sola fila (id=1) con la configuracion global del sitio. Se arranca con
        // demo_retention_days null (sin limite) a proposito -- no queremos borrar
        // demos existentes hasta que el admin elija un limite explicitamente.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('demo_retention_days')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert([
            'demo_retention_days' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
