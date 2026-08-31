<?php

namespace App\Console\Commands;

use App\Models\GameMatch;
use App\Models\Setting;
use App\Services\DiscordMatchNotifier;
use Illuminate\Console\Command;

/**
 * Postea a Discord el resultado de cada partida SD real que termino desde la
 * ultima corrida (2026-08-31) -- cron cada minuto, mismo ritmo que
 * cod2:parse-log/cod2:recalculate-stats (routes/console.php).
 *
 * "Termino de verdad" = mismo criterio que /partidas (scopeVisibleInListing,
 * ya excluye partidas fantasma/abandonadas sin resultado real, ver
 * GameMatch::scopeStillCurrent() y la bitacora de bugs en CLAUDE.md) + SD
 * (el pug prioriza Search & Destroy, mismo filtro que ya usa el listado
 * publico) + no backfilled (el "Historial importado" no tiene fecha real,
 * no tiene sentido notificar algo que no paso "ahora").
 */
class NotifyDiscordMatches extends Command
{
    protected $signature = 'cod2:notify-discord-matches';

    protected $description = 'Postea a Discord (via webhook) el resultado de las partidas SD que terminaron desde la ultima corrida';

    public function handle(): int
    {
        if (blank(Setting::current()->discord_match_webhook_url)) {
            return self::SUCCESS;
        }

        $matches = GameMatch::visibleInListing()
            ->where('gametype', 'sd')
            ->where('is_backfilled', false)
            ->whereNull('discord_notified_at')
            ->with('rounds')
            ->get();

        foreach ($matches as $match) {
            if (DiscordMatchNotifier::notify($match)) {
                $match->update(['discord_notified_at' => now()]);
                $this->info("[Discord] Notificada partida #{$match->id} ({$match->map}).");
            }
        }

        return self::SUCCESS;
    }
}
