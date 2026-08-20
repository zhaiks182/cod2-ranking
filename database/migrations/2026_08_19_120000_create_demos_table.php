<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El cliente CoD2x sube el .dm_1 crudo al terminar la partida SD (ver
        // _record.gsc::execRecording(), rama sin match oficial activado).
        //
        // hwid es un string (hash hex de 32 caracteres que devuelve self getHWID()
        // en GSC) -- NO es lo mismo que players.guid (el GUID entero que el motor
        // escribe en el log y que usa el parser de logs para identificar jugadores).
        // Son dos espacios de identificador distintos; por eso player_id no se
        // completa automaticamente todavia (ver DemoUploadController).
        Schema::create('demos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hwid');
            $table->string('demo_name');
            $table->string('file_path');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index('hwid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demos');
    }
};
