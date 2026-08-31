<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatea /adm_cod2/usuarios (2026-08-31) -- la gestion de usuarios/permisos
 * nunca es un modulo otorgable via User::MODULES (dejar que un usuario
 * comun se otorgue mas acceso a si mismo rompe todo el punto del sistema
 * de roles), solo super-admins.
 */
class EnsureIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_super_admin, 403);

        return $next($request);
    }
}
