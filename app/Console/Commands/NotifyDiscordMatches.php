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
 * "Termino de verdad" = GameMatch::scopeReadyToNotify() (2026-09-01, fix de
 * un bug real: la version original usaba scopeVisibleInListing(), que
 * tambien matchea partidas TODAVIA en curso -- ver esa fecha en CLAUDE.md,
 * "Bug real: notificaba partidas antes de tiempo") + SD (el pug prioriza
 * Search & Destroy) + no backfilled (el "Historial importado" no tiene
 * fecha real).
 *
 * `->where('ended_at', '>=', now()->subHours(2))` es un segundo seguro
 * independiente de readyToNotify(): la primera vez que se carga la URL del
 * webhook, TODAS las partidas historicas con discord_notified_at aun null
 * matchearian readyToNotify() de una -- confirmado en vivo (2026-08-31), 61
 * partidas mandadas de golpe en una rafaga de 2 minutos apenas se configuro
 * la URL. Sin este corte, cualquier futuro "apagar y prender de nuevo" el
 * webhook (o agregar un webhook nuevo) volveria a inundar el canal con todo
 * el historial. Una partida real siempre se procesa dentro del minuto de
 * terminar (mismo cron cada minuto de siempre) -- 2 horas es generoso para
 * cubrir un cron que se salteo alguna corrida, nunca para alcanzar a
 * "recuperar" partidas viejas.
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

        $matches = GameMatch::readyToNotify()
            ->where('gametype', 'sd')
            ->where('is_backfilled', false)
            ->where('ended_at', '>=', now()->subHours(2))
            ->whereNull('discord_notified_at')
            ->with(['rounds', 'server'])
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
