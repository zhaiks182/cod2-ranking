@extends('layouts.admin')

@section('title', 'Servidores')

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-lg font-semibold">Servidores</h1>
        <a href="{{ route('admin.servers.create') }}" class="px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">+ Agregar servidor</a>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between flex-wrap gap-2">
            <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Servidores temporales (self-service)</span>
            <span class="text-sm tabular-nums text-slate-300">
                <span class="font-semibold text-cyan-400">{{ $hostedServersActive }}</span>
                <span class="text-slate-500">activos ahora / {{ $hostedServersMaxConcurrent }} máximo</span>
            </span>
        </div>
        <form method="POST" action="{{ route('admin.settings.hosted-servers.update') }}" class="flex flex-wrap items-end gap-3 p-4">
            @csrf
            @method('PUT')
            <div>
                <label for="hosted_servers_max_concurrent" class="block text-xs text-slate-500 mb-1">Límite de servidores simultáneos</label>
                <input type="number" name="hosted_servers_max_concurrent" id="hosted_servers_max_concurrent" min="1"
                    value="{{ old('hosted_servers_max_concurrent', $hostedServersMaxConcurrent) }}"
                    class="w-24 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm">
                @error('hosted_servers_max_concurrent')
                    <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Guardar</button>
            <p class="text-xs text-slate-500 basis-full">
                Puertos configurados: <span class="font-mono text-slate-400">{{ $hostedServersPortRange['start'] }}-{{ $hostedServersPortRange['end'] }}</span>
                ({{ $hostedServersPortRange['end'] - $hostedServersPortRange['start'] + 1 }} puertos en el firewall). Sin límite automático contra este número —
                si el límite de arriba supera los puertos realmente abiertos, crear un servidor de más falla con un error genérico en vez de un aviso claro.
            </p>
        </form>
        <div class="px-4 pb-4 flex items-center gap-2 text-xs border-t border-slate-800 pt-3">
            <span class="text-slate-500">Cloudflare Turnstile (verificación anti-bot del formulario público):</span>
            @if($turnstileConfigured)
                <span class="text-emerald-400 font-medium">Configurado</span>
            @else
                <span class="text-amber-400 font-medium">Sin configurar — el formulario público queda sin verificación anti-bot</span>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Nombre</th>
                    <th class="px-4 py-2 font-medium">Log</th>
                    <th class="px-4 py-2 font-medium">RCON</th>
                    <th class="px-4 py-2 font-medium">Estado</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($servers as $server)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">{{ $server->name }}</td>
                        <td class="px-4 py-2 text-slate-400 font-mono text-xs">{{ $server->log_path }}</td>
                        <td class="px-4 py-2 text-slate-400 font-mono text-xs">{{ $server->rcon_host }}:{{ $server->rcon_port }}</td>
                        <td class="px-4 py-2">
                            @if($server->is_active)
                                <span class="text-emerald-400 text-xs">Activo</span>
                            @else
                                <span class="text-slate-500 text-xs">Inactivo</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                            <a href="{{ route('admin.console.show', $server) }}" class="text-cyan-400 hover:underline">Consola</a>
                            @if($server->systemd_service)
                                <a href="{{ route('admin.console.resources', $server) }}" class="text-violet-400 hover:underline">Recursos</a>
                            @endif
                            <a href="{{ route('admin.servers.edit', $server) }}" class="text-slate-300 hover:underline">Editar</a>
                            <form method="POST" action="{{ route('admin.servers.destroy', $server) }}" class="inline" onsubmit="return confirm('¿Eliminar {{ $server->name }}? Se borran también sus estadísticas.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-400 hover:underline">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">No hay servidores registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
