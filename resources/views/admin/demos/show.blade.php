@extends('layouts.admin')

@section('title', \App\Support\MapCatalog::mapLabel($match->map).' — Demos')

@section('content')
<div class="space-y-4">
    <div>
        <a href="{{ route('admin.demos.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← Volver a demos</a>
        <h1 class="text-lg font-semibold mt-1">{{ \App\Support\MapCatalog::mapLabel($match->map) }} · {{ $match->started_at->format('d/m/Y H:i') }}</h1>
        <p class="text-xs text-slate-500">{{ $demos->count() }} demo(s) · total {{ number_format($demos->sum('size_bytes') / 1024 / 1024, 1) }} MB</p>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Jugador</th>
                    <th class="px-4 py-2 font-medium">Demo</th>
                    <th class="px-4 py-2 font-medium">Hora</th>
                    <th class="px-4 py-2 font-medium text-right">Tamaño</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($demos as $demo)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            @if($demo->player)
                                <a href="{{ route('players.show', $demo->player->guid) }}" class="hover:text-cyan-400" target="_blank">{!! \App\Support\Cod2Colors::toHtml($demo->player->last_name) !!}</a>
                            @else
                                <span class="text-slate-500">Desconocido</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ $demo->demo_name }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $demo->created_at->format('H:i') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ number_format($demo->size_bytes / 1024 / 1024, 1) }} MB</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('demos.download', $demo) }}" class="text-slate-400 hover:text-cyan-400 mr-3">Descargar</a>
                            <form method="POST" action="{{ route('admin.demos.destroy', $demo) }}" class="inline" onsubmit="return confirm('¿Eliminar este demo ({{ $demo->demo_name }})? Se borra el archivo del servidor. Esta acción no se puede deshacer.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-400 hover:underline">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">No hay demos para esta partida.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
