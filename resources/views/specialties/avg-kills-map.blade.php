@extends('layouts.app')

@section('title', 'Promedio de Kills por Mapa')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.avg-kills-map', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            <span>📊</span> Promedio de Kills por Mapa
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">Bajas promedio por jugador en cada mapa (Search and Destroy) — qué tan letal es cada mapa</p>
    </div>

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay datos suficientes.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Mapa</th>
                        <th class="px-4 py-2 font-medium text-right">Promedio de bajas</th>
                        <th class="px-4 py-2 font-medium text-right">Jugadores</th>
                        <th class="px-4 py-2 font-medium text-right">Bajas totales</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">{{ $row->mapLabel }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">{{ $row->avgKills }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->players }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->total_kills }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
