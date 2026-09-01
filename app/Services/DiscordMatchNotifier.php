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
 * webhook (2026-08-31, formato final del embed reconstruido 2026-09-01 --
 * ver "Bug real: notificaba partidas antes de tiempo" en CLAUDE.md) -- mismo
 * patron de "no credenciales secretas de bot, solo una URL" que ya usa
 * DiscordWidgetService/DiscordTeamsNotifier, pero esto es POST (escribe un
 * mensaje), no GET (lee el widget publico).
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
        // tablas Axis/Allies -- gris si no hay ganador determinable. Mismo
        // par de circulos 🔴/🔵 que ya usa DiscordTeamsNotifier para Axis/Allies.
        $color = match ($winningSide) {
            'axis' => 0xdc2626,
            'allies' => 0x2563eb,
            default => 0x64748b,
        };
        $sideCircle = match ($winningSide) {
            'axis' => ' 🔴',
            'allies' => ' 🔵',
            default => '',
        };

        $matchStats = KillAggregator::aggregate(fn () => \App\Models\Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.match_id', $match->id)
            ->where('rounds.gametype', 'sd'));

        $mvp = $matchStats->sortByDesc('kills')->first();
        $topHeadshots = $matchStats->sortByDesc('headshots')->first();
        $topGrenades = $matchStats->sortByDesc('grenade_kills')->first();

        $formatLeader = fn ($row, string $countKey) => \App\Support\Cod2Colors::stripColors($row->player->last_name).' ('.$row->{$countKey}.')';

        $fields = [];

        if ($finalScore) {
            $fields[] = ['name' => 'Marcador', 'value' => $finalScore.$sideCircle, 'inline' => true];
        }

        $fields[] = ['name' => 'Duración', 'value' => $match->duration_label ?? '—', 'inline' => true];
        $fields[] = ['name' => 'Servidor', 'value' => $match->server->name, 'inline' => true];

        if ($mvp && $mvp->kills > 0) {
            $fields[] = ['name' => '🏆 MVP', 'value' => $formatLeader($mvp, 'kills'), 'inline' => true];
        }

        if ($topHeadshots && $topHeadshots->headshots > 0) {
            $fields[] = ['name' => '🎯 Headshots', 'value' => $formatLeader($topHeadshots, 'headshots'), 'inline' => true];
        }

        if ($topGrenades && $topGrenades->grenade_kills > 0) {
            $fields[] = ['name' => '💣 Granadas', 'value' => $formatLeader($topGrenades, 'grenade_kills'), 'inline' => true];
        }

        $embed = [
            'title' => "🏁 {$mapLabel}",
            'url' => route('matches.show', $match),
            'description' => 'Ver partida completa »',
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => 'Search & Destroy · '.$match->server->name, 'icon_url' => asset('logo_cod2_icon.png')],
            'timestamp' => ($match->ended_at ?? $match->started_at)->toIso8601String(),
        ];

        if ($mapImageUrl = \App\Support\MapImage::url($match->map)) {
            $embed['image'] = ['url' => $mapImageUrl];
        }

        return [
            'username' => 'CoD2 Stats',
            'avatar_url' => asset('logo_cod2_icon.png'),
            'embeds' => [$embed],
        ];
    }
}
