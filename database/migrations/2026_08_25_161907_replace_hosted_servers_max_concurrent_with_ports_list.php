<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hosted_servers_max_concurrent (un numero editable a mano) y el rango de
 * puertos configurado por .env eran dos fuentes de verdad independientes que
 * nadie sincronizaba -- subir el limite en el panel por encima de los puertos
 * realmente disponibles hacia fallar la creacion de un servidor temporal con
 * un error generico (ver el comentario que tenia Setting::maxConcurrent()
 * antes de este cambio). Se reemplaza por una lista explicita de puertos
 * (hosted_servers_ports, texto separado por comas) -- el limite pasa a ser
 * simplemente "cuantos puertos hay en la lista", asi que las dos cosas no
 * pueden desincronizarse nunca mas. Ver Setting::hostedServerPorts()/
 * maxConcurrent() y HostedServerPortAllocator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('hosted_servers_ports')->nullable()->after('hosted_servers_max_concurrent');
        });

        // Backfill con el estado actual (N consecutivos desde el rango viejo de
        // .env) para que este deploy no cambie nada visible hasta que el admin
        // edite la lista a mano desde adm_cod2/servers.
        $row = DB::table('settings')->where('id', 1)->first();

        if ($row) {
            $count = (int) ($row->hosted_servers_max_concurrent ?? config('hosted_servers.max_concurrent'));
            $start = (int) config('hosted_servers.port_range_start');
            $ports = range($start, $start + max(1, $count) - 1);

            DB::table('settings')->where('id', 1)->update([
                'hosted_servers_ports' => implode(',', $ports),
            ]);
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('hosted_servers_max_concurrent');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('hosted_servers_max_concurrent')->nullable()->after('discord_benefits');
        });

        $row = DB::table('settings')->where('id', 1)->first();

        if ($row && $row->hosted_servers_ports) {
            $count = count(explode(',', $row->hosted_servers_ports));

            DB::table('settings')->where('id', 1)->update([
                'hosted_servers_max_concurrent' => $count,
            ]);
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('hosted_servers_ports');
        });
    }
};
