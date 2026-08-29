@extends('layouts.app')

@section('title', __('Rangos'))

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('rango', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>🎖️</span> {{ __('Rangos') }}
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                {{ __('Categoría A-E según un score de 70% K/D + 30% win rate (cada uno su percentil contra el resto de jugadores calificados, Search and Destroy). Headshots y granadas se muestran en la tabla como referencia pero no entran en el cálculo.') }}
                {{ __('Mínimo :n partidas jugadas para entrar.', ['n' => $minMatches]) }}
            </p>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'specialty-season-dropdown',
            'seasonBaseRoute' => 'rango',
            'seasonBaseParams' => ['server' => $server?->slug],
        ])
    </div>

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ __('Todavía no hay suficientes jugadores calificados.') }}
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                        <th class="px-4 py-2 font-medium text-center">{{ __('Rango') }}</th>
                        <th class="px-4 py-2 font-medium text-right">K/D</th>
                        <th class="px-4 py-2 font-medium text-right">Win rate</th>
                        <th class="px-4 py-2 font-medium text-right">Headshots</th>
                        <th class="px-4 py-2 font-medium text-right">{{ __('Granadas') }}</th>
                        <th class="px-4 py-2 font-medium text-right">Score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        @php
                            $tierStyle = match($row->rango) {
                                'A' => 'bg-amber-950/40 border-amber-700 text-amber-300',
                                'B' => 'bg-cyan-950/40 border-cyan-700 text-cyan-300',
                                'C' => 'bg-slate-800/60 border-slate-600 text-slate-300',
                                'D' => 'bg-orange-950/40 border-orange-800 text-orange-400',
                                default => 'bg-red-950/40 border-red-900 text-red-400',
                            };
                        @endphp
                        @php $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">
                                @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                <x-player-icon :player="$row->player" />
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg border font-bold {{ $tierStyle }}">{{ $row->rango }}</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-300">{{ $row->kd }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->winPct }}%</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->hsPct }}%</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->nadePct }}%</td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">{{ $row->score }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
