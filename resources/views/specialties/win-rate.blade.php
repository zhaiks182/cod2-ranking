@extends('layouts.app')

@section('title', 'Win Rate por Mapa')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.win-rate', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            <span>📈</span> Win Rate por Mapa
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Mapas ganados / mapas jugados (Search and Destroy) — mínimo {{ $minMaps }} mapas jugados para entrar.
            "Jugados" es aproximado: cuenta partidas donde el jugador tuvo al menos una baja o muerte registrada.
        </p>
    </div>

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay jugadores con suficientes mapas.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Jugador</th>
                        <th class="px-4 py-2 font-medium text-right">Ganados</th>
                        <th class="px-4 py-2 font-medium text-right">Jugados</th>
                        <th class="px-4 py-2 font-medium text-right">Win rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">
                                <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-emerald-400">{{ $row->won }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->played }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">{{ $row->rate }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
