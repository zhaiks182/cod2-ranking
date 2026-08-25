<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Temporada 1" agrupa TODO el historial existente -- started_at es la fecha de
 * la partida mas vieja si hay alguna, o ahora si la base esta vacia (instalacion
 * nueva). matches.season_id se asigna una sola vez, al crear cada partida
 * (ParseCod2Log::openRound()) -- nunca se reasigna retroactivamente, por eso una
 * partida en curso cuando se cierra una temporada queda entera en la vieja sin
 * logica especial. Ver docs/superpowers/specs/2026-08-25-temporadas-infraestructura-base-design.md.
 *
 * season_id queda NULLABLE a nivel de esquema (no NOT NULL) -- forzar esa
 * constraint sobre una columna ya creada requiere Blueprint::change(), que a su
 * vez requiere doctrine/dbal (no instalado en este proyecto) y no es directo en
 * SQLite (el motor de los tests, ver phpunit.xml) sin reconstruir la tabla. La
 * garantia real de "toda partida tiene season_id" la da el codigo de aplicacion
 * (el unico lugar que crea un GameMatch es ParseCod2Log::openRound(), que
 * siempre lo setea), no la base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        $earliestMatchStartedAt = DB::table('matches')->min('started_at');

        $seasonId = DB::table('seasons')->insertGetId([
            'name' => 'Temporada 1',
            'started_at' => $earliestMatchStartedAt ?? now(),
            'ended_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedBigInteger('season_id')->nullable()->after('server_id');
            $table->foreign('season_id')->references('id')->on('seasons');
        });

        DB::table('matches')->update(['season_id' => $seasonId]);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['season_id']);
            $table->dropColumn('season_id');
        });

        Schema::dropIfExists('seasons');
    }
};
