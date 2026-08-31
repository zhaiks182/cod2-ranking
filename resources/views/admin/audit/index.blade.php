@extends('layouts.admin')

@section('title', 'Auditoría')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Auditoría</h1>
        <p class="text-xs text-slate-500 mt-1">Registro de acciones destructivas/operativas del panel admin (borrar partida/demo, kick, mensaje, cambio de mapa, reinicio de servicio, etc).</p>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 text-sm bg-panel border border-slate-800 rounded-xl px-4 py-3">
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Admin</label>
            <select name="admin" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
                <option value="">Todos</option>
                @foreach($admins as $a)
                    <option value="{{ $a->id }}" {{ (string) request('admin') === (string) $a->id ? 'selected' : '' }}>{{ $a->username ?? $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Acción contiene</label>
            <input type="text" name="action" value="{{ request('action') }}" placeholder="players. / seasons. / matches." class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200 font-mono text-xs w-48">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Desde</label>
            <input type="date" name="from" value="{{ request('from') }}" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Hasta</label>
            <input type="date" name="to" value="{{ request('to') }}" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
        </div>
        <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Filtrar</button>
        @if(request()->anyFilled(['admin', 'action', 'from', 'to']))
            <a href="{{ route('admin.audit.index') }}" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-slate-200">Quitar filtros</a>
        @endif
    </form>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Fecha</th>
                    <th class="px-4 py-2 font-medium">Admin</th>
                    <th class="px-4 py-2 font-medium">Acción</th>
                    <th class="px-4 py-2 font-medium">Detalle</th>
                </tr>
            </thead>
            <tbody>
                @forelse($actions as $entry)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 text-slate-400 whitespace-nowrap">{{ $entry->created_at->format('d/m/Y H:i:s') }}</td>
                        <td class="px-4 py-2 font-medium">{{ $entry->user->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-400 font-mono text-xs">{{ $entry->action }}</td>
                        <td class="px-4 py-2 text-slate-300">{{ $entry->description }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">{{ request()->anyFilled(['admin', 'action', 'from', 'to']) ? 'Sin resultados para este filtro.' : 'Sin registros todavía.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $actions->links() }}
</div>
@endsection
