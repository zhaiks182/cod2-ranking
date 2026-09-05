<?php

namespace App\Console\Commands;

use App\Models\Pug;
use App\Support\PugManager;
use Illuminate\Console\Command;

/**
 * Carga el proximo mapa del pug cuando termina el que se estaba jugando
 * (2026-09-05). Corre cada minuto junto al resto del pipeline, asi que puede
 * haber hasta un minuto de demora entre que termina un mapa y arranca el
 * siguiente -- el mismo retraso que ya tiene todo lo demas del sitio.
 */
class AdvancePugMaps extends Command
{
    protected $signature = 'cod2:advance-pug-maps';

    protected $description = 'Carga el siguiente mapa de cada pug en curso cuando el anterior termino';

    public function handle(): int
    {
        foreach (Pug::where('status', Pug::STATUS_PLAYING)->with('server')->get() as $pug) {
            $this->advance($pug);
        }

        return self::SUCCESS;
    }

    private function advance(Pug $pug): void
    {
        // `readyToNotify` y NO `ended_at`: ended_at lo escribe el parser despues de
        // CADA ronda y lo vuelve a poner en null al arrancar la siguiente, asi que
        // usarlo como "la partida termino" ya causo un incidente real (ver bitacora
        // de bugs, entrada 15). Este scope exige un resultado real Y que el parser
        // ya no la este rastreando como partida actual.
        $concluded = $pug->matches()->readyToNotify()->count();

        // Idempotente por construccion: solo avanza cuando hay MAS partidas
        // terminadas que mapas ya consumidos, asi que una segunda corrida del
        // scheduler sobre el mismo estado no hace nada.
        if ($concluded <= $pug->current_map_index) {
            return;
        }

        if ($pug->nextMap() === null) {
            // Se jugaron todos los mapas de la lista: el pug se cierra solo.
            PugManager::close($pug);
            $this->info("Pug {$pug->id}: terminaron todos los mapas, cerrado.");

            return;
        }

        if (PugManager::advanceToNextMap($pug)) {
            $this->info("Pug {$pug->id}: cargado {$pug->currentMap()}.");
        } else {
            $this->warn("Pug {$pug->id}: no se pudo cargar el siguiente mapa (RCON).");
        }
    }
}
