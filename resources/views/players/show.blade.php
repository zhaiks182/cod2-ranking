@extends('layouts.app')

@section('title', $player->last_name_plain)
@section('og_title', $player->last_name_plain.' — CoD2 Stats')
@section('og_description', __(':kills bajas · :deaths muertes · K/D :kd — Pug Latam', ['kills' => $player->kills_total, 'deaths' => $player->deaths_total, 'kd' => $player->kd_ratio]))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-semibold">
                {!! \App\Support\Cod2Colors::toHtml($player->last_name) !!} <x-player-icon :player="$player" />
                @if($player->siteUser?->clan_tag)<span class="text-slate-500">[{{ $player->siteUser->clan_tag }}]</span>@endif
            </h1>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-0.5">
                <span class="text-xs font-mono text-cyan-400" title="{{ __('Identificador único derivado del HWID del jugador') }}">Guid: {{ $player->guid }}</span>
                @if($player->siteUser)
                    <a href="https://discord.com/users/{{ $player->siteUser->discord_id }}" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1 rounded-full bg-[#5865F2]/15 text-[#5865F2] px-2 py-0.5 text-xs font-medium hover:bg-[#5865F2]/25 transition-colors">
                        Discord: {{ $player->siteUser->discord_username }}
                    </a>
                    @if($player->siteUser->role)
                        {{-- Insignia de comunidad (2026-09-01) -- puramente cosmetica,
                        cargada a mano por un admin desde Cuentas de Discord. --}}
                        <span class="inline-flex items-center gap-1 rounded-full bg-violet-500/15 text-violet-300 px-2 py-0.5 text-xs font-medium">
                            {{ $player->siteUser->role }}
                        </span>
                    @endif
                @endif
            </div>
            @if($canClaim)
                <form method="POST" action="{{ route('players.claim.store', $player) }}" class="mt-1">
                    @csrf
                    <button type="submit" class="text-xs text-cyan-400 hover:text-cyan-300 hover:underline">{{ __('¿Sos vos? Reclamá este perfil') }}</button>
                </form>
            @endif
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'player-season-dropdown',
            'seasonBaseRoute' => 'players.show',
            'seasonBaseParams' => [$player->guid],
        ])
    </div>

    @if($player->siteUser && ($player->siteUser->bio || $player->siteUser->steam_url || $player->siteUser->twitch_url || $player->siteUser->instagram_url || $player->siteUser->pc_cpu || $player->siteUser->pc_gpu || $player->siteUser->pc_ram || $player->siteUser->pc_peripherals))
        {{-- Solo se muestra si hay algo cargado -- sin esto, un jugador recien
        reclamado (sin bio/redes/specs todavia) mostraba una caja vacia entre
        el header y la grilla de stats. --}}
        <div id="player-profile-card" class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
            @if($player->siteUser->bio)
                <p class="text-sm text-slate-300">{{ $player->siteUser->bio }}</p>
            @endif
            @if($player->siteUser->steam_url || $player->siteUser->twitch_url || $player->siteUser->instagram_url)
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
                    @if($player->siteUser->steam_url)
                        <a href="{{ $player->siteUser->steam_url }}" target="_blank" rel="noopener" class="hover:text-gsaccent">Steam</a>
                    @endif
                    @if($player->siteUser->twitch_url)
                        <a href="{{ $player->siteUser->twitch_url }}" target="_blank" rel="noopener" class="hover:text-gsaccent">Twitch</a>
                    @endif
                    @if($player->siteUser->instagram_url)
                        <a href="{{ $player->siteUser->instagram_url }}" target="_blank" rel="noopener" class="hover:text-gsaccent">Instagram</a>
                    @endif
                </div>
            @endif
            @if($player->siteUser->pc_cpu || $player->siteUser->pc_gpu || $player->siteUser->pc_ram || $player->siteUser->pc_peripherals)
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    @if($player->siteUser->pc_cpu)<span>CPU: {{ $player->siteUser->pc_cpu }}</span>@endif
                    @if($player->siteUser->pc_gpu)<span>GPU: {{ $player->siteUser->pc_gpu }}</span>@endif
                    @if($player->siteUser->pc_ram)<span>RAM: {{ $player->siteUser->pc_ram }}</span>@endif
                    @if($player->siteUser->pc_peripherals)<span>{{ __('Periféricos') }}: {{ $player->siteUser->pc_peripherals }}</span>@endif
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-7 gap-3">
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Kills</div>
            <div class="mt-1 text-lg font-semibold text-cyan-300">
                <button type="button" data-kills-trigger data-player="{{ $player->guid }}" data-params="{{ http_build_query(['season' => $seasonId]) }}" class="px-1 py-1 -mx-1 hover:underline hover:text-cyan-200">{{ $player->kills_total }}</button>
                @if($teamkillCount > 0)
                    <button type="button" data-teamkill-trigger data-player="{{ $player->guid }}" data-params="{{ http_build_query(['season' => $seasonId]) }}" class="px-1 py-1 -my-1 text-red-500 font-medium text-base hover:underline">(-{{ $teamkillCount }})</button>
                @endif
            </div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('Muertes') }}</div>
            <div class="mt-1 text-lg font-semibold">
                <button type="button" data-deaths-trigger data-player="{{ $player->guid }}" data-params="{{ http_build_query(['season' => $seasonId]) }}" class="px-1 py-1 -mx-1 hover:underline hover:text-cyan-200">{{ $player->deaths_total }}</button>
            </div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">K/D</div>
            <div class="mt-1 text-lg font-semibold">{{ $player->kd_ratio }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Headshots</div>
            <div class="mt-1 text-lg font-semibold">
                <button type="button" data-headshots-trigger data-player="{{ $player->guid }}" data-params="{{ http_build_query(['season' => $seasonId]) }}" class="px-1 py-1 -mx-1 hover:underline hover:text-cyan-200">{{ $player->headshots_total }}</button>
                <span class="text-xs text-slate-500">({{ $player->headshot_rate }}%)</span>
            </div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('Kills con granada') }}</div>
            <div class="mt-1 text-lg font-semibold">
                <button type="button" data-grenades-trigger data-player="{{ $player->guid }}" data-params="{{ http_build_query(['season' => $seasonId]) }}" class="px-1 py-1 -mx-1 hover:underline hover:text-cyan-200">{{ $player->grenade_kills_total }}</button>
            </div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('Horas jugadas') }}</div>
            <div class="mt-1 text-lg font-semibold">{{ $hoursPlayed }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3" title="{{ __(':wins de :played partidas ganadas', ['wins' => $winRate['wins'], 'played' => $winRate['played']]) }}">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Win rate</div>
            <div class="mt-1 text-lg font-semibold">{{ $winRate['rate'] }}% <span class="text-xs text-slate-500">({{ $winRate['wins'] }}/{{ $winRate['played'] }})</span></div>
        </div>
    </div>

    @if($favoriteWeapon || $mostEquippedWeapon || $teamkillCount > 0)
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-400">
            @if($favoriteWeapon)
                <p>{{ __('Arma favorita') }}: <span class="text-slate-200 font-medium">{{ \App\Support\WeaponCatalog::label($favoriteWeapon->weapon) }}</span> ({{ __(':n bajas', ['n' => $favoriteWeapon->uses]) }})</p>
            @endif
            @if($mostEquippedWeapon)
                <p>{{ __('Arma que más usa') }}: <span class="text-slate-200 font-medium">{{ \App\Support\WeaponCatalog::label($mostEquippedWeapon->weapon) }}</span> ({{ __(':n veces equipada', ['n' => $mostEquippedWeapon->picks]) }})</p>
            @endif
            @if($teamkillCount > 0)
                <p>{{ __('Fuego amigo') }}: <span class="text-amber-400 font-medium">{{ $teamkillCount }}</span> {{ __('de sus bajas fueron contra su propio equipo') }} <span class="text-slate-600">({{ __('igual cuentan en el total, como en el marcador del juego') }})</span></p>
            @endif
        </div>
    @endif

    @if($evolutionChart->isNotEmpty())
        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">{{ __('Evolución (últimas :n partidas)', ['n' => $evolutionChart->count()]) }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel p-4">
                <canvas id="cod2-evolution-chart" height="80"></canvas>
            </div>
        </section>
    @endif

    <div class="grid md:grid-cols-2 gap-6 items-stretch">
        <section class="flex flex-col">
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">{{ __('Desempeño general por mapa') }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden flex-1 flex flex-col">
                <div class="overflow-x-auto flex-1">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-2 py-2 pl-4 font-medium">{{ __('Mapa') }}</th>
                            <th class="px-2 py-2 font-medium text-right">Kills</th>
                            <th class="px-2 py-2 font-medium text-right">{{ __('Muertes') }}</th>
                            <th class="px-2 py-2 font-medium text-right" title="{{ __('Jugadas') }}">{{ __('Jug.') }}</th>
                            <th class="px-2 py-2 font-medium text-right" title="{{ __('Ganadas') }}">{{ __('Gan.') }}</th>
                            <th class="px-2 py-2 pr-4 font-medium text-right" title="Win rate">WR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($mapPerformance->take(4) as $stat)
                            @php $tkParams = http_build_query(['server' => $stat->server?->slug, 'map' => implode(',', $stat->map_codes ?? [$stat->map]), 'season' => $seasonId]); @endphp
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-2 py-2 pl-4 whitespace-nowrap">{{ \App\Support\MapCatalog::mapLabel($stat->map) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-cyan-300">
                                    <span class="relative inline-block">
                                        <button type="button" data-kills-trigger data-player="{{ $player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $stat->kills }}</button>
                                        @if($stat->teamkills > 0)
                                            <button type="button" data-teamkill-trigger data-player="{{ $player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $stat->teamkills }})</button>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $stat->deaths }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-slate-400">{{ $stat->played }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-emerald-400">{{ $stat->wins }}</td>
                                <td class="px-2 py-2 pr-4 text-right tabular-nums text-cyan-300 font-medium">{{ $stat->rate }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">{{ __('Sin datos.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            @if($mapPerformance->count() > 4)
                <button type="button" onclick="document.getElementById('map-stats-modal').classList.remove('hidden')"
                    class="self-start mt-2 text-xs text-cyan-400 hover:underline">
                    {{ __('Ver todos los mapas (:n) →', ['n' => $mapPerformance->count()]) }}
                </button>
            @endif
        </section>

        <section class="flex flex-col">
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">{{ __('Historial de partidas') }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden flex-1 flex flex-col">
                <div class="overflow-x-auto flex-1">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-3 py-2 pl-4 font-medium">{{ __('Mapa') }}</th>
                            <th class="px-3 py-2 font-medium text-right">{{ __('Resultado') }}</th>
                            <th class="px-3 py-2 font-medium text-right">{{ __('Marcador') }}</th>
                            <th class="px-3 py-2 pr-4 font-medium text-right">{{ __('Fecha') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($matchHistory->take(4) as $h)
                            <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                                <td class="px-3 py-2 pl-4 whitespace-nowrap">
                                    <a href="{{ route('matches.show', $h->match->id) }}" class="hover:text-cyan-400">{{ \App\Support\MapCatalog::mapLabel($h->match->map) }}</a>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('matches.show', $h->match->id) }}" class="hover:opacity-80">
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium {{ $h->won ? 'bg-emerald-950 text-emerald-400 border border-emerald-900' : 'bg-red-950 text-red-400 border border-red-900' }}">{{ $h->won ? __('Ganada') : __('Perdida') }}</span>
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-cyan-300 font-medium">
                                    <a href="{{ route('matches.show', $h->match->id) }}" class="hover:text-cyan-200">{{ $h->match->final_score ?? '—' }}</a>
                                </td>
                                <td class="px-3 py-2 pr-4 text-right tabular-nums text-slate-400 whitespace-nowrap">{{ $h->match->started_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">{{ __('Sin partidas con resultado registrado todavía.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            @if($matchHistory->count() > 4)
                <button type="button" onclick="document.getElementById('match-history-modal').classList.remove('hidden')"
                    class="self-start mt-2 text-xs text-cyan-400 hover:underline">
                    {{ __('Ver todo el historial (:n) →', ['n' => $matchHistory->count()]) }}
                </button>
            @endif
        </section>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">{{ __('Alias usados') }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">{{ __('Nombres') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Fecha') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($player->aliases->take(5) as $alias)
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{!! \App\Support\Cod2Colors::toHtml($alias->name) !!}</td>
                                <td class="px-4 py-2 text-right text-xs text-slate-500">{{ $alias->last_seen_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-4 text-center text-slate-500">{{ __('Sin alias registrados.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            @if($player->aliases->count() > 5)
                <button type="button" onclick="document.getElementById('alias-modal').classList.remove('hidden')"
                    class="mt-2 text-xs text-cyan-400 hover:underline">
                    {{ __('Ver todos los alias (:n) →', ['n' => $player->aliases->count()]) }}
                </button>
            @endif
        </section>

        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">{{ __('Armas') }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">{{ __('Arma') }}</th>
                            <th class="px-4 py-2 font-medium text-right">Kills</th>
                            <th class="px-4 py-2 font-medium text-right">Headshots</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($weaponBreakdown->take(5) as $w)
                            @php $weaponParams = http_build_query(['season' => $seasonId, 'weapon' => $w->weapon]); @endphp
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{{ \App\Support\WeaponCatalog::label($w->weapon) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                    <button type="button" data-kills-trigger data-player="{{ $player->guid }}" data-params="{{ $weaponParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $w->kills }}</button>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    <button type="button" data-headshots-trigger data-player="{{ $player->guid }}" data-params="{{ $weaponParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $w->headshots }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-4 text-center text-slate-500">{{ __('Sin datos.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            @if($weaponBreakdown->count() > 5)
                <button type="button" onclick="document.getElementById('weapon-modal').classList.remove('hidden')"
                    class="mt-2 text-xs text-cyan-400 hover:underline">
                    {{ __('Ver todas las armas (:n) →', ['n' => $weaponBreakdown->count()]) }}
                </button>
            @endif
        </section>
    </div>
</div>

@if($mapPerformance->count() > 4)
    <div id="map-stats-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">{{ __('Todos los mapas (:n)', ['n' => $mapPerformance->count()]) }}</span>
                <button type="button" onclick="document.getElementById('map-stats-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">{{ __('Mapa') }}</th>
                            <th class="px-4 py-2 font-medium text-right">Kills</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Muertes') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Jugadas') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Ganadas') }}</th>
                            <th class="px-4 py-2 font-medium text-right">Win rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mapPerformance as $stat)
                            @php $tkParams = http_build_query(['server' => $stat->server?->slug, 'map' => implode(',', $stat->map_codes ?? [$stat->map]), 'season' => $seasonId]); @endphp
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{{ \App\Support\MapCatalog::mapLabel($stat->map) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                    <span class="relative inline-block">
                                        <button type="button" data-kills-trigger data-player="{{ $player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $stat->kills }}</button>
                                        @if($stat->teamkills > 0)
                                            <button type="button" data-teamkill-trigger data-player="{{ $player->guid }}" data-params="{{ $tkParams }}" class="absolute left-full top-1/2 -translate-y-1/2 ml-0.5 whitespace-nowrap px-1 py-1.5 text-[11px] text-red-500 font-medium hover:underline">(-{{ $stat->teamkills }})</button>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $stat->deaths }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $stat->played }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-emerald-400">{{ $stat->wins }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">{{ $stat->rate }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if($player->aliases->count() > 5)
    <div id="alias-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-md max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">{{ __('Todos los alias (:n)', ['n' => $player->aliases->count()]) }}</span>
                <button type="button" onclick="document.getElementById('alias-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">{{ __('Nombres') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Fecha') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($player->aliases as $alias)
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{!! \App\Support\Cod2Colors::toHtml($alias->name) !!}</td>
                                <td class="px-4 py-2 text-right text-xs text-slate-500">{{ $alias->last_seen_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if($matchHistory->count() > 4)
    <div id="match-history-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">{{ __('Todo el historial (:n)', ['n' => $matchHistory->count()]) }}</span>
                <button type="button" onclick="document.getElementById('match-history-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">{{ __('Mapa') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Resultado') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Marcador') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ __('Fecha') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matchHistory as $h)
                            <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                                <td class="px-4 py-2">
                                    <a href="{{ route('matches.show', $h->match->id) }}" class="hover:text-cyan-400">{{ \App\Support\MapCatalog::mapLabel($h->match->map) }}</a>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('matches.show', $h->match->id) }}" class="hover:opacity-80">
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium {{ $h->won ? 'bg-emerald-950 text-emerald-400 border border-emerald-900' : 'bg-red-950 text-red-400 border border-red-900' }}">{{ $h->won ? __('Ganada') : __('Perdida') }}</span>
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">
                                    <a href="{{ route('matches.show', $h->match->id) }}" class="hover:text-cyan-200">{{ $h->match->final_score ?? '—' }}</a>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $h->match->started_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if($weaponBreakdown->count() > 5)
    <div id="weapon-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">{{ __('Todas las armas (:n)', ['n' => $weaponBreakdown->count()]) }}</span>
                <button type="button" onclick="document.getElementById('weapon-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">{{ __('Arma') }}</th>
                            <th class="px-4 py-2 font-medium text-right">Kills</th>
                            <th class="px-4 py-2 font-medium text-right">Headshots</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($weaponBreakdown as $w)
                            @php $weaponParams = http_build_query(['season' => $seasonId, 'weapon' => $w->weapon]); @endphp
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{{ \App\Support\WeaponCatalog::label($w->weapon) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">
                                    <button type="button" data-kills-trigger data-player="{{ $player->guid }}" data-params="{{ $weaponParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $w->kills }}</button>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    <button type="button" data-headshots-trigger data-player="{{ $player->guid }}" data-params="{{ $weaponParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $w->headshots }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if($evolutionChart->isNotEmpty())
    {{-- Chart.js via CDN (2026-08-31) -- mismo criterio que Tailwind (CDN, sin
    build step). Solo se carga en esta pagina, no en el layout global, para no
    pesarle a paginas que no lo necesitan. --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
        (function () {
            const data = @json($evolutionChart);
            const ctx = document.getElementById('cod2-evolution-chart');
            if (!ctx || !window.Chart) return;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.label),
                    datasets: [
                        {
                            label: @json(__('Bajas')),
                            data: data.map(d => d.kills),
                            borderColor: '#22d3ee',
                            backgroundColor: 'rgba(34, 211, 238, 0.1)',
                            tension: 0.3,
                            pointRadius: 3,
                        },
                        {
                            label: @json(__('Muertes')),
                            data: data.map(d => d.deaths),
                            borderColor: '#f87171',
                            backgroundColor: 'rgba(248, 113, 113, 0.1)',
                            tension: 0.3,
                            pointRadius: 3,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { ticks: { color: '#64748b' }, grid: { color: 'rgba(100,116,139,0.1)' } },
                        y: { beginAtZero: true, ticks: { color: '#64748b' }, grid: { color: 'rgba(100,116,139,0.1)' } },
                    },
                    plugins: {
                        legend: { labels: { color: '#94a3b8' } },
                    },
                },
            });
        })();
    </script>
@endif
@endsection
