@extends('layouts.app')

@section('title', 'Reyes de Cada Mapa')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.map-kings', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>👑</span> Reyes de Cada Mapa
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">El jugador con más bajas (Search and Destroy) en cada mapa</p>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'specialty-season-dropdown',
            'seasonBaseRoute' => 'specialties.map-kings',
            'seasonBaseParams' => ['server' => $server?->slug],
        ])
    </div>

    @if($maps->isEmpty())
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
                        <th class="px-4 py-2 font-medium">Rey del mapa</th>
                        <th class="px-4 py-2 font-medium text-right">Sus bajas</th>
                        <th class="px-4 py-2 font-medium text-right">K/D</th>
                        <th class="px-4 py-2 font-medium text-right">Bajas totales en el mapa</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($maps as $i => $m)
                        @php $country = \App\Services\GeoIp::countryFor($m->topPlayer->ip); @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">
                                {{ $m->mapLabel }}
                                @if($suffix = \App\Support\MapCatalog::variantSuffix($m->map))
                                    <span class="text-cyan-400">{{ $suffix }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $m->topPlayer->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($m->topPlayer->last_name) !!}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-amber-400 font-medium">{{ $m->topKills }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-emerald-400">{{ $m->topDeaths > 0 ? round($m->topKills / $m->topDeaths, 2) : $m->topKills }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300">{{ $m->uses }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
