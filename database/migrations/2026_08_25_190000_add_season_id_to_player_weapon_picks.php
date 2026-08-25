<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mismo patron que matches.season_id (sub-proyecto 1 de temporadas) --
 * nullable a nivel de esquema (doctrine/dbal no instalado, SQLite no
 * soporta bien Blueprint::change() sin reconstruir la tabla), poblada
 * siempre por codigo de aplicacion (ParseCod2Log). El unique constraint
 * pasa a incluir season_id -- un jugador puede tener un arma "mas
 * equipada" distinta por temporada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_weapon_picks', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'weapon']);
            $table->unsignedBigInteger('season_id')->nullable()->after('player_id');
            $table->foreign('season_id')->references('id')->on('seasons');
        });

        $temporada1Id = DB::table('seasons')->orderBy('id')->value('id');

        if ($temporada1Id) {
            DB::table('player_weapon_picks')->update(['season_id' => $temporada1Id]);
        }

        Schema::table('player_weapon_picks', function (Blueprint $table) {
            $table->unique(['player_id', 'weapon', 'season_id']);
        });
    }

    public function down(): void
    {
        Schema::table('player_weapon_picks', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'weapon', 'season_id']);
            $table->dropForeign(['season_id']);
            $table->dropColumn('season_id');
            $table->unique(['player_id', 'weapon']);
        });
    }
};
