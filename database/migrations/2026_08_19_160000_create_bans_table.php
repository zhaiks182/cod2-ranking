<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El ban en si lo aplica el motor nativo de CoD2 via RCON (banClient <slot>
        // -> escribe en ban.txt en el gameserver, rechazado en cada conexion futura
        // por SV_IsBannedGuid -- confirmado leyendo el .c decompilado de CoD2MP_s en
        // el repo de CoD2x). Esta tabla es solo el registro/historial del panel: quien
        // baneo a quien, cuando, por que, y si ya se desbaneo -- ban.txt no guarda
        // nada de eso.
        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('guid');
            $table->string('player_name');
            $table->text('reason')->nullable();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unbanned_at')->nullable();
            $table->foreignId('unbanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('guid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bans');
    }
};
