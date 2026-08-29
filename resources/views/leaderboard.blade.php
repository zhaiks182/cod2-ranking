@extends('layouts.app')

@section('title', __('Ranking'))

@section('content')
<div class="space-y-4">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('leaderboard', ['server' => $s->slug, 'map' => $map, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-lg font-semibold">
            {{ __('Ranking') }} {{ $map ? '— '.\App\Support\MapCatalog::mapLabel($map) : __('general') }}
            @if($usingDateFilter && $from && $from === $to)
                <span class="text-slate-500 font-normal text-base">({{ \Illuminate\Support\Carbon::parse($from)->translatedFormat('j \d\e F') }})</span>
            @endif
        </h1>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'ranking-season-dropdown',
            'seasonBaseRoute' => 'leaderboard',
            'seasonBaseParams' => ['server' => $server?->slug, 'map' => $map],
        ])
    </div>

    <div class="flex items-center gap-2 text-sm flex-wrap">
        <a href="{{ route('leaderboard', ['server' => $server?->slug, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg border {{ !$map ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ __('General') }}</a>
        @foreach($mapGroups as $mapCode => $group)
            <a href="{{ route('leaderboard', ['server' => $server?->slug, 'map' => $mapCode, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg border {{ $map === $mapCode ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ \App\Support\MapCatalog::mapLabel($mapCode) }}</a>
        @endforeach
    </div>

    @if($map && ($mapGroups[$map]->dates ?? collect())->count() > 1)
        @php
            $monthGroups = $mapGroups[$map]->dates->groupBy(fn ($d) => $d->format('Y-m'));
        @endphp
        <div class="flex items-center gap-2 text-xs -mt-2 flex-wrap">
            <span class="text-slate-500 uppercase tracking-wide">{{ __('Atajo por sesión') }}</span>
            @foreach($monthGroups as $monthKey => $dates)
                @php $monthLabel = ucfirst($dates->first()->translatedFormat('F Y')); @endphp
                <button type="button" onclick="document.getElementById('dates-modal-{{ $monthKey }}').classList.remove('hidden')"
                    class="px-2.5 py-1 rounded-lg border {{ $dates->contains(fn ($d) => $d->toDateString() === $from) ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">
                    {{ $monthLabel }}
                </button>
            @endforeach
        </div>

        @foreach($monthGroups as $monthKey => $dates)
            @php $monthLabel = ucfirst($dates->first()->translatedFormat('F Y')); @endphp
            <div id="dates-modal-{{ $monthKey }}" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
                onclick="if(event.target === this) this.classList.add('hidden')">
                <div class="w-full max-w-sm max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
                    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                        <span class="text-sm font-semibold">{{ $monthLabel }}</span>
                        <button type="button" onclick="document.getElementById('dates-modal-{{ $monthKey }}').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
                    </div>
                    <div class="overflow-y-auto p-3 flex flex-wrap gap-2">
                        @foreach($dates as $date)
                            @php $dateStr = $date->toDateString(); @endphp
                            <a href="{{ route('leaderboard', ['server' => $server?->slug, 'map' => $map, 'season' => $seasonId, 'from' => $dateStr, 'to' => $dateStr]) }}"
                                class="px-2.5 py-1 rounded-lg border text-xs {{ $from === $dateStr ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">
                                {{ $date->translatedFormat('j \d\e F') }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    <form method="get" class="flex flex-wrap items-end gap-3 text-sm bg-panel border border-slate-800 rounded-xl px-4 py-3">
        <input type="hidden" name="server" value="{{ $server?->slug }}">
        <input type="hidden" name="map" value="{{ $map }}">
        <input type="hidden" name="season" value="{{ $seasonId }}">
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">{{ __('Desde') }}</label>
            <input type="date" name="from" value="{{ $from }}" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">{{ __('Hasta') }}</label>
            <input type="date" name="to" value="{{ $to }}" class="bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200">
        </div>
        <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">{{ __('Filtrar') }}</button>
        @if($usingDateFilter)
            <a href="{{ route('leaderboard', ['server' => $server?->slug, 'map' => $map, 'season' => $seasonId]) }}" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-slate-200">{{ __('Quitar filtro de fecha') }}</a>
        @endif
        @if(Route::has('team-balance'))
            <a href="{{ route('team-balance', ['server' => $server?->slug]) }}" class="ml-auto px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-semibold whitespace-nowrap">⚖️ {{ __('Equipos') }}</a>
        @endif
    </form>

    @php
        // El detalle de kills/fuego amigo filtra por codigo de mapa EXACTO
        // (rounds.map), que nunca es el codigo normalizado ($map, ej. mp_dawnville)
        // sino la variante real (mp_dawnville_fix/mp_dawnville_sun) — hay que mandar
        // $mapCodes (las variantes que arma esta pestaña) o el filtro no encuentra
        // ninguna ronda.
        $tkParams = http_build_query(array_filter([
            'server' => $server?->slug,
            'map' => $mapCodes ? implode(',', $mapCodes) : null,
            'season' => $seasonId,
            'from' => $from,
            'to' => $to,
        ]));
    @endphp
    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">#</th>
                    <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                    <th class="px-4 py-2 font-medium text-right">Kills</th>
                    <th class="px-4 py-2 font-medium text-right">{{ __('Muertes') }}</th>
                    <th class="px-4 py-2 font-medium text-right">K/D</th>
                    <th class="px-4 py-2 font-medium text-right">Headshots</th>
                    <th class="px-4 py-2 font-medium text-right">{{ __('Granadas') }}</th>
                    <th class="px-4 py-2 font-medium text-right" title="{{ __('Duración de las rondas SD en las que participó (tuvo al menos un kill o una muerte)') }}">{{ __('Horas') }}</th>
                    <th class="px-4 py-2 font-medium text-right" title="{{ __('Kills ÷ horas jugadas') }}">{{ __('Kills/h') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $i => $row)
                    @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                    <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                        <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                        <td class="px-4 py-2 font-medium">
                            @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                            <a href="{{ route('players.show', [$row->player->guid, 'season' => $seasonId]) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                            <x-player-icon :player="$row->player" />
                            @if($i < 3)
                                <span class="ml-1 align-text-bottom" title="{{ match($i) { 0 => __('Oro'), 1 => __('Plata'), 2 => __('Bronce') } }}">{{ match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉' } }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                            <span class="relative inline-block">
                                <button type="button" data-kills-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->kills }}</button>
                                @if($row->teamkills > 0)
                                    <button type="button" data-teamkill-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $row->teamkills }})</button>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <button type="button" data-deaths-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->deaths }}</button>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <button type="button" data-headshots-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->headshots }}</button>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <button type="button" data-grenades-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->grenade_kills }}</button>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->hours_played }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->kills_per_hour }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-slate-500">{{ __('Sin datos para esta temporada.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($axisRows->isNotEmpty() || $alliesRows->isNotEmpty())
        <div>
            <h2 class="text-sm uppercase tracking-wide text-slate-200 font-bold mb-3">{{ __('Tabla de Posiciones') }}</h2>
            <div class="grid md:grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-800 text-xs uppercase tracking-wide text-red-400 font-medium flex items-center gap-2">
                        Axis
                        @if($sideScores['axis'] !== null)
                            <span class="text-slate-400 normal-case">({{ $sideScores['axis'] }})</span>
                        @endif
                        @if($sideScores['winning'] === 'axis')
                            <span class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-900 text-[10px] normal-case tracking-normal">{{ __('Ganador') }}</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                                <th class="px-4 py-2 font-medium text-right">Kills</th>
                                <th class="px-4 py-2 font-medium text-right">{{ __('Muertes') }}</th>
                                <th class="px-4 py-2 font-medium text-right">K/D</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($axisRows->sortByDesc('kills') as $row)
                                @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                                <tr class="border-b border-slate-800/60 last:border-0">
                                    <td class="px-4 py-2 font-medium">
                                        @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                        <a href="{{ route('players.show', [$row->player->guid, 'season' => $seasonId]) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                        <x-player-icon :player="$row->player" />
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                        <span class="relative inline-block">
                                            <button type="button" data-kills-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->kills }}</button>
                                            @if($row->teamkills > 0)
                                                <button type="button" data-teamkill-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $row->teamkills }})</button>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                            <button type="button" data-deaths-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->deaths }}</button>
                        </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">{{ __('Sin datos.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-800 text-xs uppercase tracking-wide text-blue-400 font-medium flex items-center gap-2">
                        Allies
                        @if($sideScores['allies'] !== null)
                            <span class="text-slate-400 normal-case">({{ $sideScores['allies'] }})</span>
                        @endif
                        @if($sideScores['winning'] === 'allies')
                            <span class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-900 text-[10px] normal-case tracking-normal">{{ __('Ganador') }}</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                                <th class="px-4 py-2 font-medium text-right">Kills</th>
                                <th class="px-4 py-2 font-medium text-right">{{ __('Muertes') }}</th>
                                <th class="px-4 py-2 font-medium text-right">K/D</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($alliesRows->sortByDesc('kills') as $row)
                                @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                                <tr class="border-b border-slate-800/60 last:border-0">
                                    <td class="px-4 py-2 font-medium">
                                        @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                        <a href="{{ route('players.show', [$row->player->guid, 'season' => $seasonId]) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                        <x-player-icon :player="$row->player" />
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                        <span class="relative inline-block">
                                            <button type="button" data-kills-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->kills }}</button>
                                            @if($row->teamkills > 0)
                                                <button type="button" data-teamkill-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $row->teamkills }})</button>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                            <button type="button" data-deaths-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->deaths }}</button>
                        </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">{{ __('Sin datos.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
