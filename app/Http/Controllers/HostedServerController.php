<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHostedServerRequest;
use App\Models\HostedServer;
use App\Support\HostedServerProvisioner;
use App\Support\MapCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HostedServerController extends Controller
{
    public function create()
    {
        $maps = $this->mapOptions();

        return view('hosted-servers.create', [
            'maps' => $maps,
            'slotsMin' => (int) config('hosted_servers.slots_min'),
            'slotsMax' => (int) config('hosted_servers.slots_max'),
        ]);
    }

    public function store(StoreHostedServerRequest $request, HostedServerProvisioner $provisioner)
    {
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
                    'hostname' => $data['hostname'],
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
