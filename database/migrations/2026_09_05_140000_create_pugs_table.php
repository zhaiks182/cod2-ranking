<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un pug es una SESION de juego (una noche), no una partida: agrupa todas
        // las partidas que se juegan con los mismos equipos. Hasta ahora `matches`
        // registraba cada mapa por separado y no habia nada que los agrupara.
        Schema::create('pugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            // awaiting_captains -> veto -> playing -> closed
            $table->string('status', 20)->default('awaiting_captains');

            // Snapshot CONGELADO de los equipos que armo TeamBalancer al iniciar el
            // pug. A proposito no es una referencia viva al cache de
            // rememberAssignments(): si alguien regenera equipos a mitad de la noche,
            // el pug ya empezado no puede cambiar de composicion. Ademas es lo que
            // permite derivar el marcador de la sesion despues (cruzar los guids del
            // roster ganador de cada partida contra estos equipos).
            // Forma: {"A": [{"guid": 123, "name": "^1foo"}], "B": [...]}
            $table->json('teams');

            // Los dos capitanes, uno por equipo. Se postulan (el primero de cada lado
            // que reclama el rol se lo queda), por eso arrancan nulos.
            $table->foreignId('team_a_captain_site_user_id')->nullable()->constrained('site_users')->nullOnDelete();
            $table->foreignId('team_b_captain_site_user_id')->nullable()->constrained('site_users')->nullOnDelete();

            // Quien banea primero, sorteado al arrancar el veto. Sin esto habria que
            // fijar "siempre empieza A", que le da ventaja sistematica a un lado.
            $table->string('first_turn_team', 1)->nullable();

            // Pool con el que se jugo ESTE veto (copiado de settings al arrancar, no
            // referenciado): si el admin cambia el pool despues, un pug ya empezado
            // no puede quedar inconsistente.
            $table->json('veto_pool')->nullable();

            // [{"map": "mp_toujane_fix", "team": "A", "at": "..."}]
            $table->json('veto_bans')->nullable();

            // Lista ORDENADA de los mapas que sobrevivieron al veto -- es lo que se
            // anuncia en Discord y lo que se va cargando por RCON.
            $table->json('maps')->nullable();

            // Cual de `maps` esta cargado ahora mismo (0-based).
            $table->unsignedTinyInteger('current_map_index')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // Solo puede haber un pug abierto por servidor a la vez; la consulta
            // "hay pug abierto?" corre en cada creacion de partida del parser.
            $table->index(['server_id', 'status']);
        });

        Schema::table('matches', function (Blueprint $table) {
            // Se asigna UNA sola vez, cuando el parser crea la partida (mismo patron
            // que season_id, ver "Temporadas" en CLAUDE.md) -- nunca se reasigna
            // retroactivamente. Nullable porque la mayor parte del tiempo no hay
            // ningun pug abierto, a diferencia de las temporadas donde siempre hay
            // una activa.
            $table->foreignId('pug_id')->nullable()->after('season_id')->constrained('pugs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['pug_id']);
            $table->dropColumn('pug_id');
        });

        Schema::dropIfExists('pugs');
    }
};
