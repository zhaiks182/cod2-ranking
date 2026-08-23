<?php

namespace App\Support;

use App\Models\HostedServer;
use App\Services\Cod2RconClient;
use Symfony\Component\Process\Process;

/**
 * Orquesta la creacion/baja de una instancia temporal, en un orden estricto:
 *
 *   1) crear la fila en BD (status=starting, puerto ya reservado) -- COMMITEADA antes
 *      de tocar el sistema operativo, para que nunca pueda quedar un proceso real
 *      corriendo sin una fila que lo controle (si el paso 2/3/4 explota a mitad de
 *      camino, la fila SI existe y el barrido de ExpireHostedServers la va a limpiar).
 *   2) escribir el directorio/config de la instancia (HostedServerConfigWriter)
 *   3) `sudo systemctl start cod2-temp@{id}.service`
 *   4) poll de Cod2RconClient::status() unos segundos -- systemctl start devuelve OK
 *      apenas systemd hace fork+exec del binario, no cuando el gameserver termino de
 *      inicializar (mismo tipo de gotcha ya documentado en ConsoleController sobre
 *      RCON y sv_floodProtect). Recien con una respuesta real se marca "running" y se
 *      le muestra el connect string al visitante.
 *
 * Si el 2, 3 o 4 fallan: parada best-effort, se limpia el directorio, se libera el
 * puerto (nullable-unique, ver HostedServerPortAllocator), y la fila queda "failed".
 */
class HostedServerProvisioner
{
    public function __construct(
        private readonly HostedServerPortAllocator $ports,
        private readonly HostedServerConfigWriter $configWriter,
    ) {}

    public function provision(array $attributes): HostedServer
    {
        $server = $this->ports->allocate($attributes);

        try {
            $this->configWriter->write($server);
            $this->systemctl('start', $server->id);

            if ($this->waitUntilUp($server)) {
                // El reloj de inactividad (hosted-servers:expire) arranca desde que el
                // server esta realmente arriba, no desde que se creo la fila -- si no,
                // un boot que tardo 10s ya "gastaria" 10s de su ventana de inactividad
                // antes de que nadie pudiera conectarse siquiera.
                $server->update(['status' => 'running', 'last_seen_players_at' => now()]);
            } else {
                $this->teardown($server, 'failed');
            }
        } catch (\Throwable $e) {
            $this->teardown($server, 'failed');

            throw $e;
        }

        return $server->fresh();
    }

    /** Usado tanto por el boton "Detener" del creador como por ExpireHostedServers. */
    public function stop(HostedServer $server, string $finalStatus = 'stopped'): void
    {
        $this->teardown($server, $finalStatus);
    }

    private function teardown(HostedServer $server, string $finalStatus): void
    {
        $this->systemctl('stop', $server->id);
        $this->configWriter->remove($server);

        $server->update([
            'status' => $finalStatus,
            'port' => null, // libera el puerto (unique permite multiples NULL)
            'stopped_at' => now(),
        ]);
    }

    private function systemctl(string $action, int $id): void
    {
        // $id sale de una fila que ESTE MISMO metodo acaba de crear/leer, nunca de un
        // request sin validar -- pero se valida igual como defensa en profundidad,
        // mismo patron que ConsoleController::service() ya usa para el server real.
        if (! preg_match('/^[0-9]+$/', (string) $id)) {
            throw new \InvalidArgumentException('Id de instancia invalido.');
        }

        $template = config('hosted_servers.systemd_template');
        $unit = "{$template}{$id}.service";

        $process = new Process(['sudo', 'systemctl', $action, $unit]);
        $process->setTimeout(15);
        $process->run();

        // No usamos mustRun(): un "stop" sobre una unit que ya esta caida/nunca llego a
        // arrancar no deberia tirar una excepcion que corte la limpieza a mitad de
        // camino -- el estado real (arriba o no) lo confirma waitUntilUp()/el poll de
        // RCON, no el exit code de systemctl.
        if ($action === 'start' && ! $process->isSuccessful()) {
            throw new \RuntimeException('No se pudo iniciar el servicio: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * `systemctl start` para un Type=simple vuelve apenas systemd hace fork+exec, no
     * cuando el gameserver termino de inicializar -- confirmar que esta realmente
     * arriba consultando RCON de verdad antes de mostrarle el connect string a nadie.
     */
    private function waitUntilUp(HostedServer $server): bool
    {
        $client = new Cod2RconClient('127.0.0.1', $server->port, $server->rcon_password);

        for ($i = 0; $i < 10; $i++) {
            if ($client->status() !== null) {
                return true;
            }

            usleep(1_500_000);
        }

        return false;
    }
}
