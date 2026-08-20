<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El cliente sube el demo sin indicar a que partida pertenece (la URL solo
        // lleva el hwid). match_id se resuelve heuristicamente en DemoUploadController
        // como 'la partida no-importada mas reciente que empezo hace poco' -- en la
        // practica siempre es correcto porque el demo se sube segundos despues de que
        // termina la ronda, mucho antes de que arranque una partida nueva.
        Schema::table('demos', function (Blueprint $table) {
            $table->foreignId('match_id')->nullable()->after('player_id')->constrained('matches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('match_id');
        });
    }
};
