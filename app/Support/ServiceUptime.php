<?php

namespace App\Support;

use App\Models\Server;
use Carbon\Carbon;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Desde cuando esta corriendo el servicio systemd de un servidor (2026-09-05, a
 * pedido del dueño) -- la consola de admin ya podia reiniciarlo/pararlo, pero no
 * mostraba hace cuanto que esta arriba.
 *
 * A diferencia de start/stop/restart (que necesitan las reglas de
 * /etc/sudoers.d/cod2-panel, ver "Auditoría de admin y reinicio de servicio" en
 * CLAUDE.md), `systemctl show` es de solo lectura y NO necesita sudo --
 * verificado con `sudo -u www-data` contra produccion antes de escribir esto.
 * O sea: esta feature no agrega ningun permiso de sistema nuevo.
 */
class ServiceUptime
{
    public static function startedAt(Server $server): ?Carbon
    {
        // Mismo regex que ConsoleController::service(). El nombre sale de la BD y
        // no del request, pero se valida igual antes de pasarlo a Process --
        // defensa en profundidad, mismo criterio que ya usaba el resto del modulo.
        if (! $server->systemd_service || ! preg_match('/^[a-zA-Z0-9_.-]+\.service$/', $server->systemd_service)) {
            return null;
        }

        $process = new Process([
            'systemctl', 'show', $server->systemd_service,
            '-p', 'ActiveEnterTimestamp', '-p', 'ActiveState',
        ]);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (Throwable $e) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        // Un servicio parado igual devuelve ActiveEnterTimestamp (cuando arranco
        // la ultima vez), asi que sin este chequeo mostrariamos "activo hace 3
        // dias" para algo que esta caido.
        if (! str_contains($output, 'ActiveState=active')) {
            return null;
        }

        if (! preg_match('/ActiveEnterTimestamp=(.+)/', $output, $matches)) {
            return null;
        }

        // systemd emite "Tue 2026-09-01 14:18:02 -05". Se le saca el nombre del
        // dia a proposito: PHP lo trata como una restriccion y, si no coincidiera
        // con la fecha, correria el resultado al proximo dia con ese nombre en
        // vez de fallar. Con systemd nunca deberia pasar, pero sale gratis.
        $timestamp = trim(preg_replace('/^[A-Za-z]{3}\s+/', '', trim($matches[1])));

        if ($timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable $e) {
            return null;
        }
    }
}
