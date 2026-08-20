<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_resource_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu_percent', 5, 1)->nullable();
            // CPUUsageNSec de systemd es acumulado desde que arranco el servicio,
            // no una lectura instantanea -- se guarda el crudo para poder restar
            // contra la muestra anterior y sacar el % real de la ventana entre
            // ambas (ver SampleServerResources::sample()). No se muestra en la UI.
            $table->unsignedBigInteger('cpu_usage_ns_raw')->nullable();
            $table->unsignedBigInteger('memory_bytes');
            $table->unsignedBigInteger('swap_bytes');
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->index(['server_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_resource_samples');
    }
};
