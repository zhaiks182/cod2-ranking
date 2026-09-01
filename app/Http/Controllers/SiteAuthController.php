<?php

namespace App\Http\Controllers;

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

        $siteUser = SiteUser::updateOrCreate(
            ['discord_id' => $discordUser->getId()],
            [
                'discord_username' => $discordUser->getNickname() ?? $discordUser->getName(),
                'discord_avatar_url' => $discordUser->getAvatar(),
            ]
        );

        Auth::guard('site')->login($siteUser);

        return redirect()->intended(route('account.show'));
    }

    public function logout(Request $request)
    {
        Auth::guard('site')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
