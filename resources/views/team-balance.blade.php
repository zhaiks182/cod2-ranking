@extends('layouts.app')

@section('title', 'Equipos')

@section('content')
@php
    $playerCount = $status ? count($status['players']) : null;
@endphp
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('team-balance', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            <span>⚖️</span> Equipos
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Arma 2 equipos parejos a partir del rango (A-E, ver <a href="{{ route('rango') }}" class="text-cyan-400 hover:underline">Rangos</a>) de los
            jugadores conectados ahora mismo en el server. Pensada para usarse una vez que todos ya están adentro, antes de arrancar la partida.
        </p>
    </div>

    @if(!$server)
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            No hay servidores configurados todavía.
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-800 bg-panel px-4 py-4">
            <div>
                @if(!$status)
                    <div class="text-sm text-red-300">No se pudo conectar al servidor por RCON en este momento.</div>
                @else
                    <div class="text-2xl font-semibold tabular-nums">
                        <span class="text-cyan-400">{{ $playerCount }}</span>
                        <span class="text-slate-500 text-base">/ {{ $server->max_clients }}</span>
                    </div>
                    <div class="text-[11px] uppercase tracking-[0.15em] text-slate-500">Jugadores conectados ahora</div>
                @endif
            </div>
            <a href="{{ route('team-balance', ['server' => $server->slug, 'generar' => 1]) }}"
                class="px-4 py-2.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold whitespace-nowrap {{ !$status ? 'opacity-40 pointer-events-none' : '' }}">
                Generar equipos
            </a>
        </div>

        @if($teamBalance)
            @include('partials.team-balance', ['teamBalance' => $teamBalance])
        @endif
    @endif
</div>
@endsection
