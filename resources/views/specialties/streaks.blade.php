@extends('layouts.app')

@section('title', __('Racha de Mapas'))

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.streaks', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>🔥</span> {{ __('Racha de Mapas') }}
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">{{ __('Mapas completos ganados de forma consecutiva (Search and Destroy) — mínimo 2 seguidos para entrar') }}</p>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'specialty-season-dropdown',
            'seasonBaseRoute' => 'specialties.streaks',
            'seasonBaseParams' => ['server' => $server?->slug],
        ])
    </div>

    @if($longestEver)
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('Racha más larga registrada') }}</div>
                <div class="mt-1 text-lg font-semibold text-orange-400">{{ __(':n mapas', ['n' => $longestEver->best]) }} — {!! \App\Support\Cod2Colors::toHtml($longestEver->player->last_name) !!}<x-player-icon :player="$longestEver->player" /></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('Jugadores con racha activa (2+)') }}</div>
                <div class="mt-1 text-lg font-semibold text-orange-400">{{ $rows->filter(fn($r) => $r->current >= 2)->count() }}</div>
            </div>
        </div>
    @endif

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ __('Todavía no hay rachas de 2 o más mapas seguidos.') }}
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                        <th class="px-4 py-2 font-medium text-right">{{ __('Mejor racha') }}</th>
                        <th class="px-4 py-2 font-medium text-right">{{ __('Racha actual') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $r)
                        @php $country = \App\Services\GeoIp::countryFor($r->player->ip); @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">
                                @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $r->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($r->player->last_name) !!}</a>
                                <x-player-icon :player="$r->player" />
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-orange-400 font-medium">{{ $r->best }}</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ $r->current >= 2 ? 'text-lime-400' : 'text-slate-500' }}">{{ $r->current }}{{ $r->current >= 2 ? ' 🔥' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
