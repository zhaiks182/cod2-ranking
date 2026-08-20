@extends('layouts.admin')

@section('title', 'Auditoría')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Auditoría</h1>
        <p class="text-xs text-slate-500 mt-1">Registro de acciones destructivas/operativas del panel admin (borrar partida/demo, kick, mensaje, cambio de mapa, reinicio de servicio, etc).</p>
    </div>

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
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Sin registros todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $actions->links() }}
</div>
@endsection
