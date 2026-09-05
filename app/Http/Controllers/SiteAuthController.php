<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\SiteUser;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SiteAuthController extends Controller
{
    /**
     * Mapea cada tipo de conexion de Discord a la columna del perfil y a como se
     * arma la URL publica. `id` vs `name` no es intercambiable: Steam expone el
     * steamid64 en `id` (su URL canonica), mientras que Twitch/X usan el handle
     * de `name`. Instagram no esta -- Discord elimino ese tipo de conexion, asi
     * que `instagram_url` sigue siendo 100% manual.
     */
    private const DISCORD_CONNECTIONS = [
        'steam' => ['column' => 'steam_url', 'url' => 'https://steamcommunity.com/profiles/%s', 'from' => 'id'],
        'twitch' => ['column' => 'twitch_url', 'url' => 'https://twitch.tv/%s', 'from' => 'name'],
        'youtube' => ['column' => 'youtube_url', 'url' => 'https://youtube.com/channel/%s', 'from' => 'id'],
        'twitter' => ['column' => 'twitter_url', 'url' => 'https://x.com/%s', 'from' => 'name'],
    ];

    public function redirect()
    {
        // `connections` (2026-09-05) se suma a los `identify`+`email` que el
        // paquete ya pedia -- scopes() de Socialite hace merge, no reemplaza
        // (verificado en el vendor instalado antes de tocar esto: perder
        // `identify` habria roto el login entero). Como los scopes cambiaron,
        // Discord vuelve a mostrar la pantalla de autorizacion una sola vez por
        // jugador, aunque el provider mande `prompt=none`.
        return Socialite::driver('discord')->scopes(['connections'])->redirect();
    }

    public function callback()
    {
        try {
            // Timeout corto (2026-09-01) -- sin esto, un corte de red momentaneo
            // entre el VPS y Discord dejaba la request colgada mucho tiempo antes
            // de fallar (confirmado en produccion: "cURL error 52: Empty reply
            // from server" tras una espera larga). Con timeout, falla rapido y
            // el catch de abajo lo convierte en un mensaje claro en vez de un
            // error crudo.
            $discordUser = Socialite::driver('discord')
                ->setHttpClient(new Client(['timeout' => 10, 'connect_timeout' => 5]))
                ->user();
        } catch (Throwable $e) {
            // "Invalid code" (invalid_grant) es esperable si el navegador reintenta
            // el callback con un codigo ya usado (el codigo de Discord es de un
            // solo uso) -- no es un bug, solo hay que avisar y mandar a intentar
            // de nuevo en vez de mostrar un 500 crudo.
            Log::warning('discord: login fallo', ['error' => $e->getMessage()]);

            return redirect()->route('dashboard')
                ->with('error', __('No se pudo completar el login con Discord. Probá de nuevo.'));
        }

        // El payload crudo de /users/@me trae varios campos que Socialite no
        // mapea a su objeto User (global_name, locale, verified) -- ya venian en
        // cada login desde siempre con los scopes `identify`+`email` que pide
        // socialiteproviders/discord, solo que se descartaban (2026-09-05).
        $raw = $discordUser->getRaw();

        $siteUser = SiteUser::updateOrCreate(
            ['discord_id' => $discordUser->getId()],
            [
                'discord_username' => $discordUser->getNickname() ?? $discordUser->getName(),
                'discord_avatar_url' => $discordUser->getAvatar(),
                'discord_global_name' => $raw['global_name'] ?? null,
                'discord_email' => $discordUser->getEmail(),
                'discord_email_verified' => $raw['verified'] ?? null,
                'discord_locale' => $raw['locale'] ?? null,
            ]
        );

        // Autocompletar el idioma del perfil SOLO si el jugador nunca eligio uno
        // -- es un campo editable en /mi-cuenta, y pisarlo en cada login le
        // revertiria la eleccion a cualquiera que use Discord en otro idioma
        // del que quiere ver el sitio.
        if ($siteUser->language === null && $language = self::languageFromDiscordLocale($siteUser->discord_locale)) {
            $siteUser->update(['language' => $language]);
        }

        self::fillSocialLinksFromConnections($siteUser, $discordUser->token);

        Auth::guard('site')->login($siteUser);

        return redirect()->intended(route('account.show'));
    }

    /**
     * Autocompleta Steam/Twitch/YouTube/X desde las cuentas que el jugador ya
     * tiene vinculadas en Discord (2026-09-05). Las conexiones NO vienen en
     * /users/@me -- son un endpoint aparte que exige el scope `connections`.
     *
     * Nunca pisa un link que el jugador haya cargado a mano en /mi-cuenta, y
     * cualquier falla acá es silenciosa a proposito: esto corre en medio del
     * login, y no poder leer las conexiones (scope no otorgado, Discord caido,
     * token vencido) no puede impedirle a nadie entrar al sitio.
     */
    private static function fillSocialLinksFromConnections(SiteUser $siteUser, ?string $token): void
    {
        if (! $token) {
            return;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get('https://discord.com/api/users/@me/connections');

            if (! $response->successful()) {
                return;
            }

            $connections = $response->json();
        } catch (Throwable $e) {
            Log::warning('discord: no se pudieron leer las conexiones', ['error' => $e->getMessage()]);

            return;
        }

        if (! is_array($connections)) {
            return;
        }

        $updates = [];

        foreach ($connections as $connection) {
            $mapping = self::DISCORD_CONNECTIONS[$connection['type'] ?? ''] ?? null;

            // `verified` es de Discord, no nuestro: una conexion sin verificar
            // puede apuntar a una cuenta que no es del jugador.
            if (! $mapping || ! ($connection['verified'] ?? false) || ($connection['revoked'] ?? false)) {
                continue;
            }

            // Si ya hay algo cargado (a mano o de un login anterior) no se toca.
            // Con dos conexiones del mismo tipo gana la primera.
            if (filled($siteUser->{$mapping['column']}) || isset($updates[$mapping['column']])) {
                continue;
            }

            $value = $connection[$mapping['from']] ?? null;

            if (blank($value)) {
                continue;
            }

            $updates[$mapping['column']] = sprintf($mapping['url'], $value);
        }

        if ($updates) {
            $siteUser->update($updates);
        }
    }

    /**
     * "es-ES" -> "es". El locale de Discord es mucho mas granular que los dos
     * idiomas que el sitio soporta (SetLocale::SUPPORTED), y trae muchos que no
     * mapean a ninguno -- en ese caso devuelve null en vez de adivinar, y el
     * perfil queda sin idioma como antes.
     */
    private static function languageFromDiscordLocale(?string $locale): ?string
    {
        if (! $locale) {
            return null;
        }

        $language = strtolower(explode('-', $locale)[0]);

        return in_array($language, SetLocale::SUPPORTED, true) ? $language : null;
    }

    public function logout(Request $request)
    {
        Auth::guard('site')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
