<?php

namespace App\Console\Commands;

use App\Models\Demo;
use App\Support\DemoMatchResolver;
use Illuminate\Console\Command;

class ReconcileDemoMatches extends Command
{
    protected $signature = 'demos:reconcile-matches';

    protected $description = 'Re-vincula demos recientes a su partida real una vez que cod2:parse-log crea la fila (ver DemoMatchResolver)';

    public function handle(): void
    {
        // Solo demos recientes: una vez el parser se pone al dia (dentro del minuto)
        // el vinculo queda bien para siempre, no hace falta revisar demos viejos.
        $demos = Demo::where('created_at', '>=', now()->subMinutes(10))->get();

        foreach ($demos as $demo) {
            $match = DemoMatchResolver::resolve($demo->created_at, $demo->demo_name);

            if ($match && $match->id !== $demo->match_id) {
                $demo->update(['match_id' => $match->id]);
            }
        }
    }
}
