<?php

namespace App\Services;

use App\Models\Pug;
use App\Models\Setting;
use App\Support\Cod2Colors;
use App\Support\MapCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anuncia en Discord el resultado del veto: los equipos y la lista ordenada de
 * mapas que se van a jugar esa noche (2026-09-05, ver "Modulo de pugs" en
 * CLAUDE.md). Se dispara solo al cerrarse el veto.
 *
 * Reusa `discord_teams_webhook_url` -- el mismo canal donde ya se anuncian los
 * equipos armados, que es el contexto natural para esto. El webhook de
 * resultados de partida (discord_match_webhook_url) es otro a proposito.
 */
class DiscordPugNotifier
{
    /** true si se posteo; false si no hay webhook configurado o Discord fallo. */
    public static function notifyMaps(Pug $pug): bool
    {
        $webhookUrl = Setting::current()->discord_teams_webhook_url;

        if (blank($webhookUrl) || blank($pug->maps)) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post($webhookUrl, self::buildPayload($pug));

            if ($response->failed()) {
                Log::warning('discord: pug webhook fallo', ['pug_id' => $pug->id, 'status' => $response->status()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('discord: pug webhook excepcion', ['pug_id' => $pug->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function buildPayload(Pug $pug): array
    {
        $roster = fn (string $team) => collect($pug->teams[$team] ?? [])
            ->map(fn ($p) => Cod2Colors::stripColors($p['name']))
            ->implode("\n") ?: '—';

        // Numerados porque el ORDEN importa: es la secuencia en la que el panel
        // los va a ir cargando por RCON.
        $maps = collect($pug->maps)
            ->map(fn ($code, $i) => ($i + 1).'. '.MapCatalog::mapLabel($code))
            ->implode("\n");

        $banned = collect($pug->veto_bans ?? [])
            ->map(fn ($ban) => '~~'.MapCatalog::mapLabel($ban['map']).'~~')
            ->implode(' · ');

        $logoUrl = asset('logo_cod2_icon.png');

        $embed = [
            'title' => '🗺️ Mapas elegidos',
            'description' => "## {$pug->server->name}\nVeto terminado — estos son los mapas de la noche.",
            'color' => 0x22c55e,
            'fields' => array_values(array_filter([
                ['name' => 'A jugar', 'value' => $maps, 'inline' => false],
                $banned ? ['name' => 'Baneados', 'value' => $banned, 'inline' => false] : null,
                ['name' => 'Equipo A', 'value' => $roster('A'), 'inline' => true],
                ['name' => 'Equipo B', 'value' => $roster('B'), 'inline' => true],
            ])),
            'footer' => ['text' => 'Pug Latam · el servidor ya está cargando el primer mapa', 'icon_url' => $logoUrl],
            'timestamp' => now()->toIso8601String(),
        ];

        return [
            'username' => 'CoD2 Stats',
            'avatar_url' => $logoUrl,
            'embeds' => [$embed],
        ];
    }
}
