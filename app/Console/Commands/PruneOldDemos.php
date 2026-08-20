<?php

namespace App\Console\Commands;

use App\Models\Demo;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOldDemos extends Command
{
    protected $signature = 'demos:prune-old';

    protected $description = 'Borra (archivo + registro) los demos mas viejos que el limite configurado en Configuracion';

    public function handle(): void
    {
        $days = Setting::current()->demo_retention_days;

        if (! $days) {
            $this->info('Retencion de demos sin limite, no se borra nada.');

            return;
        }

        $old = Demo::where('created_at', '<', now()->subDays($days))->get();

        foreach ($old as $demo) {
            Storage::disk('local')->delete($demo->file_path);
            $demo->delete();
        }

        $this->info("Borrados {$old->count()} demo(s) con mas de {$days} dia(s).");
    }
}
