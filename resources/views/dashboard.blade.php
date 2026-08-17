@extends('layouts.app')

@section('title', 'Inicio Ranking')

@section('content')
<div class="space-y-8">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('dashboard', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <section>
        <h1 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Servidor en vivo</h1>
        @include('partials.live-status')
    </section>

    <section>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm uppercase tracking-wide text-slate-500">Top 10 jugadores</h2>
            <a href="{{ route('leaderboard', ['server' => $server?->slug]) }}" class="text-xs text-cyan-400 hover:underline">Ver ranking completo →</a>
        </div>

        @php $tkParams = http_build_query(array_filter(['server' => $server?->slug])); @endphp
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Jugador</th>
                        <th class="px-4 py-2 font-medium text-right">Kills</th>
                        <th class="px-4 py-2 font-medium text-right">Muertes</th>
                        <th class="px-4 py-2 font-medium text-right">K/D</th>
                        <th class="px-4 py-2 font-medium text-right">Headshots</th>
                        <th class="px-4 py-2 font-medium text-right">Granadas</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topPlayers as $i => $stat)
                        @php $country = \App\Services\GeoIp::countryFor($stat->player->ip); @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">
                                @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $stat->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($stat->player->last_name) !!}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                <span class="relative inline-block">
                                    <button type="button" data-kills-trigger data-player="{{ $stat->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $stat->kills }}</button>
                                    @if($stat->teamkills > 0)
                                        <button type="button" data-teamkill-trigger data-player="{{ $stat->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $stat->teamkills }})</button>
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $stat->deaths }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $stat->kd_ratio }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $stat->headshots }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $stat->grenade_kills }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Todavía no hay estadísticas registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </section>
</div>
@endsection
