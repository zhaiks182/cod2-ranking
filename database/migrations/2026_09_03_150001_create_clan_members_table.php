<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            // unique(): un jugador solo puede pertenecer a un clan a la vez
            // (2026-09-03, a pedido del dueño) -- garantizado a nivel de
            // esquema, no solo por convencion del codigo.
            $table->foreignId('site_user_id')->unique()->constrained('site_users')->cascadeOnDelete();
            // string, no enum() -- mismo criterio que hosted_servers.status
            // (validado en el codigo, no a nivel de esquema).
            $table->string('role', 16)->default('member');
            $table->timestamp('joined_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_members');
    }
};
