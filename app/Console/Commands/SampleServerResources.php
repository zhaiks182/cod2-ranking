<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerResourceSample;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SampleServerResources extends Command
{
    protected $signature = 'cod2:sample-resources';

    protected $description = 'Toma una muestra de CPU/RAM/swap del servicio systemd de cada servidor activo, para el grafico de recursos del panel de consola';

    public function handle(): int
    {
        $servers = Server::where('is_active', true)->whereNotNull('systemd_service')->get();

        foreach ($servers as $server) {
            $this->sample($server);
        }

        return self::SUCCESS;
    }

    private function sample(Server $server): void
    {
        // systemctl show no requiere sudo para leer propiedades (a diferencia de
        // start/stop/restart, que si lo necesitan) -- confirmado que www-data
        // puede correr esto directo, sin tocar /etc/sudoers.
        $process = new Process([
            'systemctl', 'show', $server->systemd_service,
            '--property=MemoryCurrent,MemorySwapCurrent,CPUUsageNSec,ActiveState',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return;
        }

        $props = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $props[$key] = $value;
        }

        // Servicio detenido (lo paro un admin, o esta reiniciando) -- no tiene
        // sentido guardar una muestra de "0 MB, 0% CPU", eso leeria como un
        // servidor sano e inactivo en vez de simplemente "sin datos ahora".
        if (($props['ActiveState'] ?? null) !== 'active') {
            return;
        }

        $memoryBytes = is_numeric($props['MemoryCurrent'] ?? null) ? (int) $props['MemoryCurrent'] : 0;
        $swapBytes = is_numeric($props['MemorySwapCurrent'] ?? null) ? (int) $props['MemorySwapCurrent'] : 0;
        $cpuNs = is_numeric($props['CPUUsageNSec'] ?? null) ? (int) $props['CPUUsageNSec'] : null;

        $cpuPercent = null;
        $previous = ServerResourceSample::where('server_id', $server->id)
            ->latest('sampled_at')
            ->first();

        // CPUUsageNSec es acumulado desde que arranco el servicio, no una lectura
        // instantanea de "% ahora" -- hay que restar contra la muestra anterior y
        // dividir por el tiempo real transcurrido entre ambas. Si el servicio se
        // reinicio entre muestras, el contador vuelve a (casi) cero y el actual
        // queda MENOR al anterior -- eso no es "CPU negativo", es que el contador
        // se reinicio, asi que se salta el % esta vuelta y solo se guarda el
        // crudo nuevo como punto de partida para la proxima.
        if ($cpuNs !== null && $previous?->cpu_usage_ns_raw !== null && $cpuNs >= $previous->cpu_usage_ns_raw) {
            // Calculo directo por timestamps en vez de diffInSeconds(): en
            // Carbon 3 (Laravel 13) varios diffInX() pasaron a devolver un valor
            // con signo por defecto, y con $previous en el pasado esto devolvia
            // negativo -- el check ">0" de abajo nunca pasaba y cpu_percent
            // quedaba siempre NULL (confirmado con datos reales el 2026-08-20).
            $elapsedSeconds = now()->timestamp - $previous->sampled_at->timestamp;
            if ($elapsedSeconds > 0) {
                $deltaNs = $cpuNs - $previous->cpu_usage_ns_raw;
                $cpuPercent = round(($deltaNs / ($elapsedSeconds * 1_000_000_000)) * 100, 1);
            }
        }

        ServerResourceSample::create([
            'server_id' => $server->id,
            'cpu_percent' => $cpuPercent,
            'cpu_usage_ns_raw' => $cpuNs,
            'memory_bytes' => $memoryBytes,
            'swap_bytes' => $swapBytes,
            'sampled_at' => now(),
        ]);
    }
}
