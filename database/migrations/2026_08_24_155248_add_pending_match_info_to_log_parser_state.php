<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_parser_state', function (Blueprint $table) {
            // El valor crudo del campo _match_info del InitGame: mas reciente (ej.
            // "Round 0 | MR12 Ready-up" o "Round 1 | MR12 ") -- se usa para que
            // RoundStart; pueda distinguir la fase de ready-up (Round 0, nunca
            // gameplay real) de una ronda de verdad, sin importar el gametype (a
            // diferencia de la exclusion existente de "strat", que es especifica
            // de ese gametype). Persistido igual que pending_map/pending_gametype
            // por si InitGame: y RoundStart; quedan separados entre dos corridas
            // del cron (poco probable, pero ambos ya usan el mismo patron).
            $table->string('pending_match_info')->nullable()->after('pending_gametype');
        });
    }

    public function down(): void
    {
        Schema::table('log_parser_state', function (Blueprint $table) {
            $table->dropColumn('pending_match_info');
        });
    }
};
