@extends('layouts.admin')

@section('title', 'Servidores')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Servidores</h1>
        <a href="{{ route('admin.servers.create') }}" class="px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">+ Agregar servidor</a>
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
