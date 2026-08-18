@extends('layouts.app')

@section('title', 'Inicio Ranking')

@section('content')
<div class="space-y-7">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('dashboard', ['server' => $s->slug]) }}" class="px-3 py-1.5 text-xs uppercase tracking-wide {{ $server?->id === $s->id ? 'text-gsaccent' : 'text-slate-500 hover:text-slate-300' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <section>
        <h1 class="flex items-center gap-2 text-[11px] uppercase tracking-[0.2em] text-slate-500 mb-4">
            <span class="relative flex h-2 w-2" aria-hidden="true">
                <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
            </span>
            Servidor en vivo
        </h1>
        @include('partials.live-status')
    </section>

    <section>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Top 10 jugadores</h2>
            <a href="{{ route('leaderboard', ['server' => $server?->slug]) }}" class="text-xs text-gsaccent hover:underline">Ver ranking completo →</a>
        </div>

        @php $tkParams = http_build_query(array_filter(['server' => $server?->slug])); @endphp
        <div class="overflow-x-auto rounded-xl border border-slate-800 bg-panel">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[10px] uppercase tracking-[0.15em] text-slate-600 border-b border-slate-800">
                    <th class="px-4 py-2.5 font-medium w-8"></th>
                    <th class="px-4 py-2.5 font-medium">Jugador</th>
                    <th class="px-4 py-2.5 font-medium text-right">Kills</th>
                    <th class="px-4 py-2.5 font-medium text-right">Muertes</th>
                    <th class="px-4 py-2.5 font-medium text-right">K/D</th>
                    <th class="px-4 py-2.5 font-medium text-right">Headshots</th>
                    <th class="px-4 py-2.5 font-medium text-right">Granadas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/50">
                @forelse($topPlayers as $i => $stat)
                    @php $country = \App\Services\GeoIp::countryFor($stat->player->ip); @endphp
                    <tr class="hover:bg-slate-800/30 transition-colors duration-150">
                        <td class="px-4 py-3 tabular-nums font-semibold {{ match(true) {
                            $i === 0 => 'text-amber-400',
                            $i === 1 => 'text-slate-400',
                            $i === 2 => 'text-orange-400/80',
                            default => 'text-slate-600',
                        } }}">{{ $i + 1 }}</td>
                        <td class="px-4 py-3 font-medium">
                            @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                            <a href="{{ route('players.show', $stat->player->guid) }}" class="hover:text-gsaccent">{!! \App\Support\Cod2Colors::toHtml($stat->player->last_name) !!}</a>
                            @if($i < 3)
                                <span class="ml-1 align-text-bottom" title="{{ match($i) { 0 => 'Oro', 1 => 'Plata', 2 => 'Bronce' } }}">{{ match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉' } }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-cyan-300">
                            <span class="relative inline-block">
                                <button type="button" data-kills-trigger data-player="{{ $stat->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $stat->kills }}</button>
                                @if($stat->teamkills > 0)
                                    <button type="button" data-teamkill-trigger data-player="{{ $stat->player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $stat->teamkills }})</button>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-400">{{ $stat->deaths }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-400">{{ $stat->kd_ratio }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-400">{{ $stat->headshots }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-400">{{ $stat->grenade_kills }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-600">Todavía no hay estadísticas registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </section>
</div>
@endsection
