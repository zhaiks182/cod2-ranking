<?php

namespace App\Console\Commands;

use App\Support\StatsRecalculator;
use Illuminate\Console\Command;

class RecalculateStats extends Command
{
    protected $signature = 'cod2:recalculate-stats';

    protected $description = 'Reconstruye kills_total/player_map_stats/player_server_stats desde kills, excluyendo partidas abandonadas sin resultado real';

    public function handle(): int
    {
        StatsRecalculator::recalculateAll();

        $this->info('Estadisticas recalculadas.');

        return self::SUCCESS;
    }
}
