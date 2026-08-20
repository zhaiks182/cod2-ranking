<?php

namespace App\Console\Commands;

use App\Models\ServerResourceSample;
use Illuminate\Console\Command;

class PruneResourceSamples extends Command
{
    protected $signature = 'cod2:prune-resource-samples';

    protected $description = 'Borra muestras de CPU/RAM de mas de 24 horas (una por minuto se acumula rapido)';

    public function handle(): int
    {
        $deleted = ServerResourceSample::where('sampled_at', '<', now()->subDay())->delete();

        $this->info("Borradas {$deleted} muestras de mas de 24h.");

        return self::SUCCESS;
    }
}
