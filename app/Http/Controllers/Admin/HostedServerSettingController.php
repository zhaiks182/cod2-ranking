<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HostedServer;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Config de servidores temporales self-service (2026-09-01, separado de
 * ServerController/SettingController a pedido del dueño -- antes vivia
 * embebido en /adm_cod2/servers, mezclado con el gameserver real de Pug
 * Latam, y el modulo 'servers' otorgaba las dos cosas juntas sin poder
 * separarlas. Ver User::MODULES para el porque de la separacion.
 */
class HostedServerSettingController extends Controller
{
    public function index()
    {
        $hostedServersActive = HostedServer::whereIn('status', ['starting', 'running'])->count();
        $hostedServersPorts = Setting::current()->hostedServerPorts();
        $hostedServersMaxConcurrent = Setting::maxConcurrent();
        $turnstileConfigured = filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));

        return view('admin.hosted-servers.index', compact(
            'hostedServersActive',
            'hostedServersPorts',
            'hostedServersMaxConcurrent',
            'turnstileConfigured',
        ));
    }

    /**
     * Lista de puertos de servidores temporales (Setting::hostedServerPorts()) --
     * el limite de concurrencia (maxConcurrent()) se deriva solo de cuantos
     * puertos hay en la lista, ver esa clase. Validacion: formato (numeros
     * validos, sin duplicados, al menos uno), que ningun puerto choque con un
     * server REAL activo (tabla servers, ver mas abajo -- el dueño se topo con
     * esto en vivo asignandole a un temporal el mismo puerto que Pug Latam), y
     * que no se saque un puerto que tiene AHORA MISMO un servidor temporal
     * activo (se rechaza el guardado entero en vez de dejarlo corriendo "fuera
     * de lista", a pedido explicito del dueño).
     */
    public function update(Request $request)
    {
        $request->validate([
            'hosted_servers_ports' => ['present', 'array'],
            'hosted_servers_ports.*' => ['nullable', 'string'],
        ]);

        $ports = collect($request->input('hosted_servers_ports', []))
            ->map(fn ($port) => trim((string) $port))
            ->filter(fn ($port) => $port !== '')
            ->values();

        if ($ports->isEmpty()) {
            return back()->withErrors(['hosted_servers_ports' => 'Ingresá al menos un puerto.'])->withInput();
        }

        if ($ports->contains(fn ($port) => ! ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535)) {
            return back()->withErrors(['hosted_servers_ports' => 'Cada puerto debe ser un número entre 1024 y 65535.'])->withInput();
        }

        $portInts = $ports->map(fn ($port) => (int) $port);

        if ($portInts->duplicates()->isNotEmpty()) {
            return back()->withErrors(['hosted_servers_ports' => 'No repitas el mismo puerto más de una vez.'])->withInput();
        }

        // Un server real (tabla servers, ej. Pug Latam) ya tiene ese puerto UDP
        // abierto de forma permanente -- asignarselo tambien a un servidor temporal
        // choca en el mismo VPS. Solo mira servers activos (is_active=true); uno
        // desactivado ya no compite por el puerto.
        $realServerPorts = Server::where('is_active', true)
            ->get(['connect_port', 'rcon_port'])
            ->flatMap(fn ($server) => [$server->connect_port, $server->rcon_port])
            ->unique();

        $collidingWithRealServer = $portInts->intersect($realServerPorts);

        if ($collidingWithRealServer->isNotEmpty()) {
            $list = $collidingWithRealServer->implode(', ');

            return back()
                ->withErrors(['hosted_servers_ports' => "El puerto {$list} ya lo usa un servidor real (revisá /adm_cod2/servers)."])
                ->withInput();
        }

        $portsInUse = HostedServer::whereIn('status', ['starting', 'running'])
            ->whereNotNull('port')
            ->pluck('port');

        $removedPortsInUse = $portsInUse->diff($portInts);

        if ($removedPortsInUse->isNotEmpty()) {
            $list = $removedPortsInUse->implode(', ');

            return back()
                ->withErrors(['hosted_servers_ports' => "No se puede sacar el puerto {$list}: tiene un servidor temporal activo ahora mismo."])
                ->withInput();
        }

        Setting::current()->update(['hosted_servers_ports' => $portInts->implode(',')]);

        return back()->with('status', 'Puertos de servidores temporales actualizados.');
    }
}
