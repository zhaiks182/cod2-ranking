<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['es', 'en'];

    /**
     * El español es el idioma "canonico" -- las plantillas tienen el texto en
     * español directo dentro de __(), sin claves abstractas, y lang/en.json
     * traduce esas mismas frases. Por eso no hace falta locale=es en la
     * cookie para que el sitio se vea en español: es simplemente lo que
     * __() devuelve cuando no hay traduccion activa.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // /adm_cod2 se queda en español siempre, sin importar la cookie del
        // visitante -- decision explicita del dueño (el panel es solo para el,
        // no tiene selector propio). Importa en la practica porque
        // partials/team-balance.blade.php SI esta traducido (se comparte con
        // la pagina publica /equipos) -- sin este corte, la cookie "en" de una
        // visita anterior al sitio publico se filtraria a ese partial dentro
        // del panel admin.
        if ($request->is('adm_cod2*')) {
            return $next($request);
        }

        $locale = $request->cookie('locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            App::setLocale($locale);
            // App::setLocale() no sincroniza esto solo -- varias vistas usan
            // ->translatedFormat() (nombres de mes/dia en /ranking, /partidas,
            // etc.), que lee el locale ESTATICO de Carbon, no el de Laravel.
            Carbon::setLocale($locale);
        }

        return $next($request);
    }
}
