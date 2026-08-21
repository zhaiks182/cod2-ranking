<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DiscordWidgetService
{
    private const CACHE_KEY = 'discord:widget';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Lee la Widget API publica de Discord (Configuracion del servidor >
     * Widget habilitado, requiere "Comunidad" activa) -- sin token de bot ni
     * credenciales secretas. Solo trae miembros CONECTADOS ahora mismo, no
     * el total real del server (eso necesitaria un bot con el intent
     * GUILD_MEMBERS y un token secreto, fuera de alcance de esta version).
     *
     * El guild_id sale de Setting (editable en adm_cod2/discord) en vez de
     * .env -- asi el dueño puede cambiar de server de Discord sin pasar por
     * un deploy.
     *
     * @return array{name: ?string, online: int, members: array<int, array>}|null null si
     *         no hay guild_id configurado, o si Discord no responde/el
     *         widget esta apagado -- la vista lo trata como "seccion oculta".
     */
    public static function fetch(): ?array
    {
        $guildId = Setting::current()->discord_guild_id;
        if (! $guildId) {
            return null;
        }

        // Mismo criterio que Cod2RconClient/DashboardController::loadServerData():
        // solo se cachea un exito. Cachear un null (Discord caido, timeout, o el
        // widget deshabilitado) dejaria la seccion oculta por 60s enteros en vez
        // de reintentar en la proxima visita.
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(4)->get("https://discord.com/api/guilds/{$guildId}/widget.json");
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        $result = [
            'name' => $data['name'] ?? null,
            'online' => $data['presence_count'] ?? count($data['members'] ?? []),
            // online primero, despues idle, despues no molestar -- para que la
            // lista (recortada a los primeros N en la vista) muestre a los
            // realmente disponibles antes que a los que estan en "no molestar".
            'members' => collect($data['members'] ?? [])
                ->sortBy(fn ($m) => match ($m['status'] ?? '') {
                    'online' => 0,
                    'idle' => 1,
                    'dnd' => 2,
                    default => 3,
                })
                ->values()
                ->all(),
        ];

        Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /** Invalida la cache de 60s -- llamado cuando el admin cambia el guild_id en adm_cod2/discord. */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
