<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Setting;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use App\Support\TeamSideAnalyzer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Postea el resultado de una partida terminada a un canal de Discord via
 * webhook (2026-08-31) -- mismo patron de "no credenciales secretas de bot,
 * solo una URL" que ya usa DiscordWidgetService para el widget de la home,
 * pero esto es POST (escribe un mensaje), no GET (lee el widget publico).
 *
 * La URL sale de Setting::current()->discord_match_webhook_url (editable en
 * adm_cod2/discord, ver DiscordSettingController) -- se genera desde
 * Discord mismo (Configuracion del canal > Integraciones > Webhooks), no
 * hay forma de crearla desde este repo.
 */
class DiscordMatchNotifier
{
    /** true si se postea con exito, false si no hay webhook configurado o Discord devuelve error. */
    public static function notify(GameMatch $match): bool
    {
        $webhookUrl = Setting::current()->discord_match_webhook_url;

        if (blank($webhookUrl)) {
            return false;
        }

        $payload = self::buildPayload($match);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->failed()) {
                Log::warning('discord: match webhook fallo', ['match_id' => $match->id, 'status' => $response->status()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('discord: match webhook excepcion', ['match_id' => $match->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function buildPayload(GameMatch $match): array
    {
        $mapLabel = MapCatalog::mapLabel($match->map);
        $finalScore = $match->final_score;
        $matchKills = \App\Models\Kill::where('match_id', $match->id)
            ->get(['attacker_guid', 'attacker_team', 'victim_guid', 'victim_team']);
        $winningSide = TeamSideAnalyzer::winningSideForMatch($match->rounds, $matchKills);

        // Rojo axis / azul allies, mismos tonos que ya usa el sitio en las
        // tablas Axis/Allies -- gris si no hay ganador determinable.
        $color = match ($winningSide) {
            'axis' => 0xdc2626,
            'allies' => 0x2563eb,
            default => 0x64748b,
        };

        $mvp = KillAggregator::aggregate(fn () => \App\Models\Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.match_id', $match->id)
            ->where('rounds.gametype', 'sd'))
            ->sortByDesc('kills')
            ->first();

        $fields = [];

        if ($finalScore) {
            $fields[] = ['name' => 'Marcador', 'value' => $finalScore.($winningSide ? ' ('.ucfirst($winningSide).' ganó)' : ''), 'inline' => true];
        }

        $fields[] = ['name' => 'Duración', 'value' => $match->duration_label ?? '—', 'inline' => true];

        if ($mvp) {
            $fields[] = [
                'name' => 'MVP',
                'value' => \App\Support\Cod2Colors::stripColors($mvp->player->last_name).' ('.$mvp->kills.' bajas)',
                'inline' => true,
            ];
        }

        $embed = [
            'title' => "🏁 {$mapLabel}",
            'url' => route('matches.show', $match),
            'description' => 'Search & Destroy · Pug Latam',
            'color' => $color,
            'fields' => $fields,
            'timestamp' => ($match->ended_at ?? $match->started_at)->toIso8601String(),
        ];

        if ($mapImageUrl = \App\Support\MapImage::url($match->map)) {
            $embed['thumbnail'] = ['url' => $mapImageUrl];
        }

        return [
            'username' => 'CoD2 Stats',
            'embeds' => [$embed],
        ];
    }
}
