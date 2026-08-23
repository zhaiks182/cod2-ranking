<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHostedServerRequest;
use App\Models\HostedServer;
use App\Support\HostedServerProvisioner;
use App\Support\MapCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HostedServerController extends Controller
{
    public function create()
    {
        $maps = $this->mapOptions();

        $active = HostedServer::whereIn('status', ['starting', 'running'])->count();
        $available = max(0, (int) config('hosted_servers.max_concurrent') - $active);

        return view('hosted-servers.create', [
            'maps' => $maps,
            'slotsMin' => (int) config('hosted_servers.slots_min'),
            'slotsMax' => (int) config('hosted_servers.slots_max'),
            'available' => $available,
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ]);
    }

    public function store(StoreHostedServerRequest $request, HostedServerProvisioner $provisioner)
    {
        // Verificacion de Turnstile ANTES de tomar el lock de concurrencia -- es una
        // llamada HTTP a Cloudflare, no tiene sentido tener el lock (que otros
        // requests estan esperando) agarrado mientras se espera esa respuesta externa.
        if (! $this->passesTurnstile($request)) {
            return back()->withInput()->with('error', 'No se pudo verificar que sos una persona. Probá de nuevo.');
        }

        // Tope global de concurrencia -- un simple COUNT() antes de crear tiene la
        // misma carrera que un SELECT+lock sobre una tabla con pocas filas activas (ver
        // HostedServerPortAllocator), asi que la seccion "contar + decidir si hay
        // lugar" corre entera bajo un lock atomico de cache (Cache::lock), no una
        // simple comparacion en PHP -- dos requests simultaneos nunca pueden colarse
        // los dos a la vez.
        $lock = Cache::lock('hosted-servers-create', 10);

        try {
            $lock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return back()->withInput()->with('error', 'Hay mucha demanda ahora mismo, probá de nuevo en unos segundos.');
        }

        try {
            $active = HostedServer::whereIn('status', ['starting', 'running'])->count();

            if ($active >= (int) config('hosted_servers.max_concurrent')) {
                return back()->withInput()->with('error', 'No hay servidores disponibles ahora mismo — ya se alcanzó el máximo de servidores activos. Probá de nuevo más tarde.');
            }

            $data = $request->validated();

            try {
                $server = $provisioner->provision([
                    // El sufijo " @ Pug Latam" es fijo, no lo elige el usuario -- se
                    // pega aca, una sola vez, asi el nombre guardado (mostrado despues
                    // en la pagina del server Y en el juego) ya viene con marca de la
                    // comunidad siempre, sin depender de que cada vista se acuerde de
                    // agregarlo.
                    'hostname' => trim($data['hostname']).HostedServer::NAME_SUFFIX,
                    'slots' => $data['slots'],
                    'map' => $data['map'],
                    'join_password' => $data['join_password'] ?: null,
                    'rcon_password' => Str::random(12),
                    'cracked' => $request->boolean('cracked'),
                    'management_token' => Str::random(40),
                    'expires_at' => now()->addHours((int) config('hosted_servers.expiry_hours')),
                    'creator_ip' => $request->ip(),
                    'status' => 'starting',
                ]);
            } catch (\Throwable $e) {
                report($e);

                return back()->withInput()->with('error', 'No se pudo crear el servidor ahora mismo. Probá de nuevo en un momento.');
            }
        } finally {
            $lock->release();
        }

        if ($server->status === 'failed') {
            return back()->withInput()->with('error', 'No se pudo iniciar el servidor. Probá de nuevo en un momento.');
        }

        return redirect()->route('hosted-servers.show', [$server, $server->management_token]);
    }

    public function show(HostedServer $hostedServer, string $token)
    {
        $this->authorizeToken($hostedServer, $token);

        return view('hosted-servers.show', ['server' => $hostedServer]);
    }

    public function stop(Request $request, HostedServer $hostedServer, string $token, HostedServerProvisioner $provisioner)
    {
        $this->authorizeToken($hostedServer, $token);

        if ($hostedServer->isActive()) {
            $provisioner->stop($hostedServer, 'stopped');
        }

        return redirect()->route('hosted-servers.show', [$hostedServer, $token])->with('status', 'Servidor detenido.');
    }

    /**
     * Si Turnstile no esta configurado (sin TURNSTILE_SITE_KEY/SECRET_KEY en .env,
     * ej. en dev) se salta la verificacion en vez de romper el form -- el honeypot +
     * throttle + lock de concurrencia siguen aplicando igual. Una vez que el dueño
     * cargue las keys reales de Cloudflare (Turnstile > Add site en su dashboard, no
     * se pueden generar desde aca), esto se activa solo.
     */
    private function passesTurnstile(Request $request): bool
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

    private function authorizeToken(HostedServer $hostedServer, string $token): void
    {
        // hash_equals en vez de === -- el token es la unica "credencial" del creador
        // (no hay login), asi que compararlo en tiempo constante evita que una
        // diferencia de timing filtre de a poco cuantos caracteres coinciden.
        if (! hash_equals($hostedServer->management_token, $token)) {
            abort(404);
        }
    }

    /** @return array<string,string> codigo => etiqueta, mismos mapas que ya estan confirmados instalados (ver admin/console.blade.php) */
    private function mapOptions(): array
    {
        $options = MapCatalog::all();

        foreach (MapCatalog::variantCodes() as $code) {
            $suffix = MapCatalog::variantSuffix($code);
            $options[$code] = MapCatalog::mapLabel($code).($suffix ? " {$suffix}" : '');
        }

        asort($options);

        return $options;
    }
}
