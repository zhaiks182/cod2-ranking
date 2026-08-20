<?php

namespace App\Console\Commands;

use App\Models\ServerResourceSample;
use Illuminate\Console\Command;

class PruneResourceSamples extends Command
{
    protected $signature = 'cod2:prune-resource-samples';

    protected $description = 'Borra muestras de CPU/RAM de mas de 48 horas (una por minuto se acumula rapido)';

    public function handle(): int
    {
        // 48h para que coincida con la ventana que pide ConsoleController
        // (fetchResourceSamples) -- si se cambia uno hay que cambiar el otro.
        $deleted = ServerResourceSample::where('sampled_at', '<', now()->subDays(2))->delete();

        $this->info("Borradas {$deleted} muestras de mas de 48h.");

        return self::SUCCESS;
    }
}
