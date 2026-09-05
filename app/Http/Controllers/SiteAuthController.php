<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\SiteUser;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SiteAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('discord')->redirect();
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

        Auth::guard('site')->login($siteUser);

        return redirect()->intended(route('account.show'));
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
