@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route($routeName, array_filter(['server' => $s->slug, 'stat' => $stat ?? null])) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    @isset($tabs)
        <div class="flex gap-2 text-sm">
            @foreach($tabs as $tab)
                <a href="{{ $tab['url'] }}" class="px-3 py-1.5 rounded-lg border {{ $tab['active'] ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $tab['label'] }}</a>
            @endforeach
        </div>
    @endisset

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>{{ $icon }}</span> {{ $title }}
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">{{ $subtitle }}</p>
        </div>

        @isset($seasonId)
            @include('partials.season-selector', [
                'seasonDropdownId' => 'specialty-season-dropdown',
                'seasonBaseRoute' => $routeName,
                'seasonBaseParams' => array_filter(['server' => $server?->slug, 'stat' => $stat ?? null]),
            ])
        @endisset
    </div>

    @if(count($statCards))
        <div class="grid grid-cols-2 md:grid-cols-{{ min(count($statCards), 3) }} gap-3">
            @foreach($statCards as $card)
                <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-lg font-semibold {{ $card['color'] ?? '' }}">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ __('Todavía no hay datos suficientes para este ranking.') }}
        </div>
    @else
        @php $podium = $rows->take(3); $rest = $rows->slice(3)->values(); @endphp

        <div class="grid grid-cols-3 gap-3 items-end">
            <div class="order-1">
                @if($podium->count() > 1)
                    @php $p = $podium[1]; $country = \App\Services\GeoIp::countryFor($p->player->ip); @endphp
                    <div class="rounded-xl border border-slate-700 bg-panel px-3 py-4 text-center {{ ($p->inactive ?? false) ? 'opacity-40' : '' }}">
                        <div class="text-2xl">🥈</div>
                        @if($country)<span title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                        <a href="{{ route('players.show', $p->player->guid) }}" class="mt-1 block font-medium text-sm truncate hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->player->last_name) !!}<x-player-icon :player="$p->player" /></a>
                        <div class="mt-1 text-xl font-bold {{ $valueColor }}">{{ $p->value }}</div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide">{{ $valueLabel }}</div>
                    </div>
                @endif
            </div>

            <div class="order-2">
                @if($podium->count() > 0)
                    @php $p = $podium[0]; $country = \App\Services\GeoIp::countryFor($p->player->ip); @endphp
                    <div class="rounded-xl border-2 border-amber-500 bg-gradient-to-b from-amber-950/40 to-panel px-3 py-6 text-center shadow-lg shadow-amber-950/50 {{ ($p->inactive ?? false) ? 'opacity-40' : '' }}">
                        <div class="text-3xl">🥇</div>
                        @if($country)<span title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                        <a href="{{ route('players.show', $p->player->guid) }}" class="mt-1 block font-semibold truncate hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->player->last_name) !!}<x-player-icon :player="$p->player" /></a>
                        <div class="mt-1 text-3xl font-bold {{ $valueColor }}">{{ $p->value }}</div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide">{{ $valueLabel }}</div>
                        @if($shareLabel && $p->share !== null)
                            <div class="mt-1 text-[11px] text-slate-400">{{ $p->share }}% {{ $shareLabel === __('% de sus bajas') ? __('de sus bajas') : $shareLabel }}</div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="order-3">
                @if($podium->count() > 2)
                    @php $p = $podium[2]; $country = \App\Services\GeoIp::countryFor($p->player->ip); @endphp
                    <div class="rounded-xl border border-slate-700 bg-panel px-3 py-3 text-center {{ ($p->inactive ?? false) ? 'opacity-40' : '' }}">
                        <div class="text-xl">🥉</div>
                        @if($country)<span title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                        <a href="{{ route('players.show', $p->player->guid) }}" class="mt-1 block font-medium text-sm truncate hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->player->last_name) !!}<x-player-icon :player="$p->player" /></a>
                        <div class="mt-1 text-lg font-bold {{ $valueColor }}">{{ $p->value }}</div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide">{{ $valueLabel }}</div>
                    </div>
                @endif
            </div>
        </div>

        @if($rest->isNotEmpty())
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">#</th>
                            <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ ucfirst($valueLabel) }}</th>
                            @if($shareLabel)
                                <th class="px-4 py-2 font-medium text-right">{{ $shareLabel }}</th>
                            @endif
                            <th class="px-4 py-2 font-medium text-right">{{ __('Kills totales') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rest as $i => $row)
                            @php $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                            <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30 {{ ($row->inactive ?? false) ? 'opacity-40' : '' }}">
                                <td class="px-4 py-2 text-cyan-400">{{ $i + 4 }}</td>
                                <td class="px-4 py-2 font-medium">
                                    @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                    <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                    <x-player-icon :player="$row->player" />
                                    @if($row->inactive ?? false)
                                        <span class="ml-1 text-[10px] text-slate-500">{{ __('(inactivo)') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $valueColor }} font-medium">{{ $row->value }}</td>
                                @if($shareLabel)
                                    <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->share !== null ? $row->share.'%' : '—' }}</td>
                                @endif
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">{{ $row->kills ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
