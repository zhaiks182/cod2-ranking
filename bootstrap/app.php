<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/adm_cod2/login');

        // El cliente CoD2x no puede mandar un token CSRF al subir el demo.
        $middleware->validateCsrfTokens(except: [
            'api/demos/upload/*',
        ]);

        // El sitio esta detras de Cloudflare (proxy) -- sin esto, $request->ip()
        // devuelve siempre un borde de Cloudflare, nunca el visitante real (rango
        // 172.64.0.0/13 confirmado en produccion: dos servidores temporales creados
        // por la misma persona quedaron con el mismo "creator_ip" de Cloudflare,
        // ver CLAUDE.md 2026-08-25). Solo se confia en X-Forwarded-For cuando la
        // conexion TCP real vino de una de estas IPs -- alguien que le pegue directo
        // al origen (151.245.32.43 o direct.cod2.4livepro.com, que a proposito no
        // pasa por Cloudflare) no puede spoofear su IP con un header falso, porque
        // Laravel/Symfony solo lo respetan si el peer inmediato esta en esta lista.
        // Lista oficial, rara vez cambia: https://www.cloudflare.com/ips/
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/29',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
