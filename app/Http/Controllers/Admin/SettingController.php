<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
     * Limite de servidores temporales concurrentes (Setting::maxConcurrent()).
     * Sin tope contra la cantidad real de puertos abiertos en el firewall a
     * proposito (2026-08-24, pedido explicito del dueño) -- ver el comentario
     * en Setting::maxConcurrent() sobre que pasa si se sube de mas.
     */
    public function updateHostedServers(Request $request)
    {
        $validated = $request->validate([
            'hosted_servers_max_concurrent' => ['required', 'integer', 'min:1'],
        ]);

        Setting::current()->update([
            'hosted_servers_max_concurrent' => $validated['hosted_servers_max_concurrent'],
        ]);

        return back()->with('status', 'Límite de servidores temporales actualizado.');
    }
}
