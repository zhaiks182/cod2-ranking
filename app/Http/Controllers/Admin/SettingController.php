<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HostedServer;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'demo_retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        Setting::current()->update([
            'demo_retention_days' => $validated['demo_retention_days'] ?? null,
        ]);

        return back()->with('status', 'Configuracion guardada.');
    }

    /**
     * Lista de puertos de servidores temporales (Setting::hostedServerPorts()) --
     * el limite de concurrencia (maxConcurrent()) se deriva solo de cuantos
     * puertos hay en la lista, ver esa clase. Validacion en dos partes: formato
     * (numeros validos, sin duplicados, al menos uno) y una regla de negocio
     * (no se puede sacar un puerto que tiene AHORA MISMO un servidor temporal
     * activo -- se rechaza el guardado entero en vez de dejar ese servidor
     * corriendo "fuera de lista", a pedido explicito del dueño).
     */
    public function updateHostedServers(Request $request)
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
