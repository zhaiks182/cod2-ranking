<?php

namespace App\Providers;

use App\Models\HostedServer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Comparte $activeHostedServer con el layout publico en TODAS las paginas
        // (no solo las de hosted-servers) -- asi el icono de "tenes un server
        // activo" al lado del logo aparece sin importar donde este navegando el
        // visitante, sin tener que agregar esto a mano en cada controller. Ver
        // HostedServerController::store()/stop() para donde se pone/saca la cookie.
        View::composer('layouts.app', function ($view) {
            $view->with('activeHostedServer', $this->resolveActiveHostedServer());
        });

        // Registra el driver de Discord para Socialite (paquete
        // socialiteproviders/discord -- Discord no es un driver oficial).
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });
    }

    private function resolveActiveHostedServer(): ?HostedServer
    {
        $cookie = request()->cookie(HostedServer::COOKIE_NAME);

        if (! $cookie || ! str_contains($cookie, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $cookie, 2);
        $server = HostedServer::find($id);

        // hash_equals aca tambien -- la cookie es conveniencia, no la credencial en
        // si (esa sigue siendo el token comparado siempre en tiempo constante, mismo
        // patron que HostedServerController::authorizeToken()). Si el server ya no
        // esta activo (detenido/expirado/nunca existio/token no matchea), no se
        // muestra el icono -- no vale la pena mandar a nadie a una pagina muerta.
        if (! $server || ! hash_equals($server->management_token, $token) || ! $server->isActive()) {
            return null;
        }

        return $server;
    }
}
