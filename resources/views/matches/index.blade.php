@extends('layouts.app')

@section('title', 'Partidas')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('matches.index', ['server' => $s->slug, 'from' => $from, 'to' => $to]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <h1 class="text-lg font-semibold">Historial de partidas</h1>

    <form method="get" class="flex flex-wrap items-end gap-3 text-sm bg-panel border border-slate-800 rounded-xl px-4 py-3">
        <input type="hidden" name="server" value="{{ $server?->slug }}">
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Desde</label>
            <input type="date" name="from" value="{{ $from }}" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Hasta</label>
            <input type="date" name="to" value="{{ $to }}" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
        </div>
        <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Filtrar</button>
        @if($from || $to)
            <a href="{{ route('matches.index', ['server' => $server?->slug]) }}" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-slate-200">Quitar filtro</a>
        @endif
    </form>

    @if($backfilled->isNotEmpty())
        <section>
            <h2 class="text-xs uppercase tracking-wide text-slate-500 mb-2">
                Historial importado <span class="normal-case text-slate-600">(fecha no disponible — datos cargados desde el log antes de empezar el seguimiento en vivo)</span>
            </h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @foreach($backfilled as $match)
                    <a href="{{ route('matches.show', $match) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-800/30">
                        <div class="flex items-center gap-3">
                            @if($mapImageUrl = \App\Support\MapImage::url($match->map))
                                <img src="{{ $mapImageUrl }}" alt="" class="h-12 w-12 rounded-lg object-cover shrink-0">
                            @endif
                            <div>
                                <div class="font-medium">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</div>
                                <div class="text-xs text-slate-500">{{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }}</div>
                            </div>
                        </div>
                        <div class="text-sm text-slate-400">{{ $match->kills_count }} bajas →</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @forelse($grouped as $date => $dayMatches)
        <section>
            <h2 class="text-xs uppercase tracking-wide text-slate-500 mb-2">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('l j \d\e F, Y') }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @foreach($dayMatches as $match)
                    <a href="{{ route('matches.show', $match) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-800/30">
                        <div class="flex items-center gap-3">
                            @if($mapImageUrl = \App\Support\MapImage::url($match->map))
                                <img src="{{ $mapImageUrl }}" alt="" class="h-12 w-12 rounded-lg object-cover shrink-0">
                            @endif
                            <div>
                                <div class="font-medium">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }} · {{ $match->started_at->format('H:i') }}@if($match->ended_at) – {{ $match->ended_at->format('H:i') }}@endif · {{ $match->duration_label }}
                                    @if($match->ended_at)
                                        · <span class="text-emerald-400">Finalizado</span>
                                    @else
                                        · <span class="text-emerald-400">(en curso)</span>
                                    @endif
                                    @if($match->final_score)
                                        · <span class="text-slate-300 font-medium">{{ $match->final_score }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="text-sm text-slate-400">{{ $match->kills_count }} bajas →</div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        @if($backfilled->isEmpty())
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-6 text-center text-sm text-slate-500">Sin partidas registradas todavía.</div>
        @endif
    @endforelse

    <div>{{ $matches->links() }}</div>
</div>
@endsection
