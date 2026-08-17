@extends('layouts.admin')

@section('title', 'Partidas')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Partidas</h1>
    </div>

    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('admin.matches.index', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Mapa</th>
                    <th class="px-4 py-2 font-medium">Modo</th>
                    <th class="px-4 py-2 font-medium">Fecha</th>
                    <th class="px-4 py-2 font-medium">Tiempo jugado</th>
                    <th class="px-4 py-2 font-medium text-right">Rondas</th>
                    <th class="px-4 py-2 font-medium text-right">Kills</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($matches as $match)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ route('matches.show', $match) }}" class="hover:text-cyan-400" target="_blank">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }}</td>
                        <td class="px-4 py-2 text-slate-400">
                            @if($match->is_backfilled)
                                <span class="text-slate-600">importado</span>
                            @else
                                {{ $match->started_at->format('d/m/Y H:i') }}
                                @unless($match->ended_at)
                                    <span class="ml-1 text-[10px] uppercase tracking-wide text-emerald-400">en curso</span>
                                @endunless
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ $match->is_backfilled ? '—' : $match->duration_label }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $match->rounds_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $match->kills_count }}</td>
                        <td class="px-4 py-2 text-right">
                            <form method="POST" action="{{ route('admin.matches.destroy', $match) }}" class="inline" onsubmit="return confirm('¿Eliminar esta partida ({{ \App\Support\MapCatalog::mapLabel($match->map) }})? Se borran sus rondas y kills, y se recalculan las estadísticas de todos los jugadores. Esta acción no se puede deshacer.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-400 hover:underline">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Sin partidas registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div>{{ $matches->links() }}</div>
</div>
@endsection
