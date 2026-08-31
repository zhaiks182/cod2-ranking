<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatea un grupo de rutas admin a un modulo de User::MODULES (2026-08-31,
 * sistema de roles) -- uso: ->middleware('module:servers'). Va DESPUES de
 * 'auth' en el grupo de rutas (nunca antes), asi que $request->user() ya
 * esta resuelto para cuando este middleware corre.
 */
class EnsureHasModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($request->user()?->hasModule($module), 403);

        return $next($request);
    }
}
