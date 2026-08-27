@extends('layouts.app')

@section('title', 'Países')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>🌎</span> Países
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">Países de los Jugadores</p>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'specialty-season-dropdown',
            'seasonBaseRoute' => 'specialties.countries',
            'seasonBaseParams' => [],
        ])
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Países distintos</div>
            <div class="mt-1 text-lg font-semibold text-cyan-400">{{ $countries->count() }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Jugadores con país detectado</div>
            <div class="mt-1 text-lg font-semibold text-cyan-400">{{ $totalWithCountry }} / {{ $totalPlayers }}</div>
        </div>
    </div>

    @if($countries->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay suficientes datos de geolocalización.
        </div>
    @else
        <div class="space-y-4">
            @foreach($countries as $c)
                <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-800 bg-slate-900/40">
                        <div class="font-medium flex items-center gap-2">
                            {!! $c->flag !!} {{ $c->name }}
                        </div>
                        <div class="text-xs text-slate-500">{{ $c->count }} {{ $c->count === 1 ? 'jugador' : 'jugadores' }} · {{ $c->share }}%</div>
                    </div>
                    <div class="px-4 py-3 flex flex-wrap gap-x-6 gap-y-1.5">
                        @foreach($c->players as $p)
                            <a href="{{ route('players.show', $p->guid) }}" class="text-sm text-slate-300 hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->last_name) !!}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
