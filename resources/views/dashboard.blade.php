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
                            default => 'text-cyan-400',
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

    @if($discord)
        <section class="rounded-2xl border border-slate-800 bg-panel p-6 md:p-8">
            <div class="grid md:grid-cols-2 gap-8 items-start">
                {{-- Columna izquierda: presentacion + botones + beneficios --}}
                <div>
                    {{-- El punto/texto reflejan el estado REAL del gameserver ($status,
                    la misma consulta RCON que ya usa "Servidor en vivo" arriba) en vez
                    de ser un badge decorativo fijo -- a pedido del dueño. --}}
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border {{ $status ? 'border-emerald-800/60 bg-emerald-950/40 text-emerald-300' : 'border-slate-700 bg-slate-900/60 text-slate-500' }} text-[10px] uppercase tracking-wide">
                        @if($status)
                            <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
                                <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                            </span>
                            Servidor activo
                        @else
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span>
                            Servidor sin conexión
                        @endif
                    </span>
                    <h2 class="font-display text-2xl md:text-3xl font-bold text-white mt-4 mb-2">Unite a nuestro Discord</h2>
                    <p class="text-sm text-slate-400 mb-5">Chateá con la comunidad, reportá jugadores, y enterate de novedades del server en vivo.</p>

                    <div class="flex flex-wrap items-center gap-3 mb-6">
                        @if(config('services.discord.invite_url'))
                            <a href="{{ config('services.discord.invite_url') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-[#5865F2] hover:bg-[#4752c4] text-white text-sm font-semibold transition-colors">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                Unirme a Discord
                            </a>
                        @endif
                        <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-slate-700 text-sm text-slate-300">
                            <span class="relative flex h-2 w-2" aria-hidden="true">
                                <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                            </span>
                            <span id="discord-online-count">{{ $discord['online'] }} online ahora</span>
                        </span>
                    </div>

                    <div class="space-y-3 text-sm text-slate-400">
                        <div class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-indigo-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                            Alertas de partidas y anuncios del server
                        </div>
                        <div class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                            Reportá tramposos y pedí soporte rápido
                        </div>
                        <div class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-cyan-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                            Coordiná PUGs y partidas de Search &amp; Destroy
                        </div>
                        <div class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-amber-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                            Seguí tu ranking y estadísticas en vivo acá en el sitio
                        </div>
                        <div class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-violet-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/></svg>
                            Descargá demos de las partidas jugadas
                        </div>
                        <div class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-rose-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                            Descubrí rivalidades, rachas y récords del server
                        </div>
                    </div>
                </div>

                {{-- Columna derecha: tarjeta de miembros conectados --}}
                @include('partials.discord-community')
            </div>
        </section>
    @endif
</div>

@if($discord)
    <script>
        // Auto-refresh del widget de Discord cada 45s -- la Widget API de
        // Discord ya esta cacheada 60s del lado del server (ver
        // DiscordWidgetService), asi que no tiene sentido pedirla mas seguido
        // que eso; 45s da margen para que casi siempre pegue en cache.
        //
        // El script va ACA, en dashboard.blade.php, y no dentro de
        // partials/discord-community.blade.php, a proposito: ese partial se
        // vuelve a renderizar SOLO (sin este script) en cada fetch del
        // refresh -- si el script estuviera adentro del partial, se
        // re-inyectaria y duplicaria (setInterval apilandose) en cada vuelta.
        // Mismo problema ya resuelto asi en el dashboard de Recursos del
        // panel admin.
        (function () {
            function refreshDiscordWidget() {
                var el = document.getElementById('discord-widget');
                if (!el) return;
                var url = el.dataset.refreshUrl;
                if (!url) return;

                fetch(url).then(function (r) { return r.text(); }).then(function (html) {
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    var fresh = wrapper.querySelector('#discord-widget');
                    if (!fresh) return;

                    var countEl = document.getElementById('discord-online-count');
                    if (countEl && fresh.dataset.online) {
                        countEl.textContent = fresh.dataset.online + ' online ahora';
                    }

                    el.replaceWith(fresh);
                }).catch(function () {});
            }

            setInterval(refreshDiscordWidget, 45000);
        })();
    </script>
@endif
@endsection
