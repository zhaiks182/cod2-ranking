@extends('layouts.admin')

@section('title', 'Servidores')

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
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

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Servidores temporales (self-service)</span>
                @if($turnstileConfigured)
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-emerald-950/60 text-emerald-400">Turnstile OK</span>
                @else
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-950/60 text-amber-400" title="El formulario público de creación queda sin verificación anti-bot">Turnstile sin configurar</span>
                @endif
            </div>
            <div class="flex items-center gap-2 w-44">
                <div class="flex-1 h-1.5 rounded-full bg-panel2 overflow-hidden">
                    <div class="h-full bg-cyan-500" style="width: {{ $hostedServersMaxConcurrent > 0 ? min(100, round($hostedServersActive / $hostedServersMaxConcurrent * 100)) : 0 }}%"></div>
                </div>
                <span class="text-xs tabular-nums text-slate-300 shrink-0">{{ $hostedServersActive }}/{{ $hostedServersMaxConcurrent }} activos</span>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.settings.hosted-servers.update') }}" class="p-4 space-y-3">
            @csrf
            @method('PUT')

            <div id="hosted-servers-port-slots" class="space-y-2">
                @foreach($hostedServersPorts as $i => $port)
                    <div class="flex items-center gap-2" data-port-slot>
                        <label class="text-xs text-slate-500 w-40 shrink-0">Servidor temporal #{{ $i + 1 }}</label>
                        <input type="number" name="hosted_servers_ports[]" min="1024" max="65535"
                            value="{{ $port }}" aria-label="Puerto para servidor temporal #{{ $i + 1 }}"
                            class="w-32 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm font-mono">
                        <button type="button" onclick="cod2RemovePortSlot(this)" aria-label="Quitar este servidor temporal"
                            class="w-8 h-8 shrink-0 rounded-lg border border-slate-700 text-slate-400 hover:text-red-400 hover:border-red-800 flex items-center justify-center">✕</button>
                    </div>
                @endforeach
            </div>

            @error('hosted_servers_ports')
                <p class="text-[11px] text-red-400">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-3">
                <button type="button" onclick="cod2AddPortSlot()" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-700 hover:text-cyan-400 text-sm font-medium">+ Agregar servidor temporal</button>
                <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Guardar</button>
            </div>
            <p class="text-xs text-slate-500">
                El límite de servidores simultáneos es la cantidad de puertos de arriba — no hay un número aparte que pueda desincronizarse.
                Sacar un puerto que tiene un servidor temporal activo ahora mismo no se permite hasta que ese servidor se libere.
            </p>
        </form>

        <script>
            function cod2PortSlotRows() {
                return document.querySelectorAll('#hosted-servers-port-slots [data-port-slot]');
            }

            function cod2RenumberPortSlots() {
                cod2PortSlotRows().forEach((row, i) => {
                    row.querySelector('label').textContent = 'Servidor temporal #' + (i + 1);
                    row.querySelector('input').setAttribute('aria-label', 'Puerto para servidor temporal #' + (i + 1));
                });
            }

            function cod2AddPortSlot() {
                const container = document.getElementById('hosted-servers-port-slots');
                const rows = cod2PortSlotRows();
                const lastInput = rows.length ? rows[rows.length - 1].querySelector('input') : null;
                const suggested = lastInput && lastInput.value ? (parseInt(lastInput.value, 10) + 10) : 28970;
                const n = rows.length + 1;

                const row = document.createElement('div');
                row.className = 'flex items-center gap-2';
                row.setAttribute('data-port-slot', '');
                row.innerHTML = '<label class="text-xs text-slate-500 w-40 shrink-0">Servidor temporal #' + n + '</label>'
                    + '<input type="number" name="hosted_servers_ports[]" min="1024" max="65535" value="' + suggested + '"'
                    + ' aria-label="Puerto para servidor temporal #' + n + '"'
                    + ' class="w-32 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm font-mono">'
                    + '<button type="button" onclick="cod2RemovePortSlot(this)" aria-label="Quitar este servidor temporal"'
                    + ' class="w-8 h-8 shrink-0 rounded-lg border border-slate-700 text-slate-400 hover:text-red-400 hover:border-red-800 flex items-center justify-center">✕</button>';
                container.appendChild(row);
            }

            function cod2RemovePortSlot(button) {
                if (cod2PortSlotRows().length <= 1) return; // siempre al menos 1 fila
                button.closest('[data-port-slot]').remove();
                cod2RenumberPortSlots();
            }
        </script>
    </div>
</div>
@endsection
