<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;
use App\Support\Cod2Colors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Postea a Discord los equipos que se acaban de armar en /equipos (boton
 * "Notificar Discord", 2026-08-31) -- webhook separado del de resultados de
 * partida (discord_teams_webhook_url, no discord_match_webhook_url) para
 * poder mandarlo a otro canal. Los jugadores que llegan aca YA fueron
 * revalidados contra RCON por TeamBalancer::matchConnected() -- esta clase
 * no vuelve a tocar la red mas que para postear el webhook.
 */
class DiscordTeamsNotifier
{
    /** true si se postea con exito, false si no hay webhook configurado o Discord devuelve error. */
    public static function notify(Server $server, Collection $axisPlayers, Collection $alliesPlayers): bool
    {
        $webhookUrl = Setting::current()->discord_teams_webhook_url;

        if (blank($webhookUrl)) {
            return false;
        }

        $payload = self::buildPayload($server, $axisPlayers, $alliesPlayers);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->failed()) {
                Log::warning('discord: teams webhook fallo', ['server_id' => $server->id, 'status' => $response->status()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('discord: teams webhook excepcion', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function buildPayload(Server $server, Collection $axisPlayers, Collection $alliesPlayers): array
    {
        $formatRoster = fn (Collection $players) => $players
            ->map(fn ($p) => Cod2Colors::stripColors($p->name).($p->rango ? " ({$p->rango})" : ''))
            ->implode("\n") ?: '—';

        // Mismo "Score total: X vs Y" que ya muestra partials/team-balance.blade.php
        // en /equipos -- sumado de vuelta desde $ranks (matchConnected() lo deja
        // en cada jugador), no confiado del formulario.
        $scoreAxis = round($axisPlayers->sum('score'), 1);
        $scoreAllies = round($alliesPlayers->sum('score'), 1);

        $logoUrl = asset('logo_cod2_icon.png');

        $embed = [
            'title' => '⚖️ Equipos armados',
            // $server->name YA es "Pug Latam" en este server -- no repetirlo aca
            // (era literal antes, se duplicaba con el nombre del server). "## "
            // es un heading de markdown de Discord (texto mas grande/en negrita)
            // -- los embeds no tienen control de tamano de fuente propio, esta
            // es la unica forma real de agrandar letras dentro de uno.
            'description' => "## Search & Destroy · {$server->name}\n## Score total: {$scoreAxis} vs {$scoreAllies}",
            'color' => 0x06b6d4,
            // Inline -- a diferencia del truncado de nombres de
            // DiscordMatchNotifier (una LINEA que se pasa desnivela la fila),
            // aca cada valor es una lista de varias lineas: Discord no
            // desnivela por eso, solo dispareja el alto visual si un equipo
            // tiene mas jugadores que el otro, que es aceptable.
            'fields' => [
                ['name' => 'Axis 🔴', 'value' => $formatRoster($axisPlayers), 'inline' => true],
                ['name' => 'Allies 🔵', 'value' => $formatRoster($alliesPlayers), 'inline' => true],
            ],
            'footer' => ['text' => 'Pug Latam · cambio de equipo a mano en el juego', 'icon_url' => $logoUrl],
            'timestamp' => now()->toIso8601String(),
        ];

        return [
            'username' => 'CoD2 Stats',
            'avatar_url' => $logoUrl,
            'embeds' => [$embed],
        ];
    }
}
