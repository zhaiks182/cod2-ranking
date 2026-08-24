<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Verificacion de Cloudflare Turnstile, compartida entre cualquier form
 * publico/sin-login expuesto a bots (servidores temporales, login de admin
 * -- ambos son blanco tipico de fuerza bruta/spam). Antes vivia como metodo
 * privado de HostedServerController; extraida a este helper (2026-08-24)
 * cuando se agrego el segundo uso real en Admin\AuthController.
 */
class TurnstileVerifier
{
    public static function passes(Request $request): bool
    {
        if (! config('services.turnstile.secret_key')) {
            return true;
        }

        $token = $request->input('cf-turnstile-response');
        if (! $token) {
            return false;
        }

        $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret_key'),
            'response' => $token,
            'remoteip' => $request->ip(),
        ]);

        return $response->successful() && ($response->json('success') === true);
    }
}
