@extends('layouts.app')

@section('title', $player->last_name_plain)

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-semibold">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</h1>
            <div class="text-xs font-mono text-cyan-400 mt-0.5" title="Identificador único derivado del HWID del jugador">guid {{ $player->guid }}</div>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'player-season-dropdown',
            'seasonBaseRoute' => 'players.show',
            'seasonBaseParams' => [$player->guid],
        ])
    </div>

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
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Muertes</div>
            <div class="mt-1 text-lg font-semibold">{{ $player->deaths_total }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">K/D</div>
            <div class="mt-1 text-lg font-semibold">{{ $player->kd_ratio }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Headshots</div>
            <div class="mt-1 text-lg font-semibold">{{ $player->headshots_total }} <span class="text-xs text-slate-500">({{ $player->headshot_rate }}%)</span></div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Kills con granada</div>
            <div class="mt-1 text-lg font-semibold">{{ $player->grenade_kills_total }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Horas jugadas</div>
            <div class="mt-1 text-lg font-semibold">{{ $hoursPlayed }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3" title="{{ $winRate['wins'] }} de {{ $winRate['played'] }} partidas ganadas">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Win rate</div>
            <div class="mt-1 text-lg font-semibold">{{ $winRate['rate'] }}% <span class="text-xs text-slate-500">({{ $winRate['wins'] }}/{{ $winRate['played'] }})</span></div>
        </div>
    </div>

    <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-400">
        @if($favoriteWeapon)
            <p>Arma favorita: <span class="text-slate-200 font-medium">{{ \App\Support\WeaponCatalog::label($favoriteWeapon->weapon) }}</span> ({{ $favoriteWeapon->uses }} bajas)</p>
        @endif
        @if($mostEquippedWeapon)
            <p>Arma que más usa: <span class="text-slate-200 font-medium">{{ \App\Support\WeaponCatalog::label($mostEquippedWeapon->weapon) }}</span> ({{ $mostEquippedWeapon->picks }} veces equipada)</p>
        @endif
        @if($teamkillCount > 0)
            <p>Fuego amigo: <span class="text-amber-400 font-medium">{{ $teamkillCount }}</span> de sus bajas fueron contra su propio equipo <span class="text-slate-600">(igual cuentan en el total, como en el marcador del juego)</span></p>
        @endif
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Mejores mapas</h2>
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">Mapa</th>
                            <th class="px-4 py-2 font-medium text-right">Kills</th>
                            <th class="px-4 py-2 font-medium text-right">Muertes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($player->mapStats->take(4) as $stat)
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
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-4 text-center text-slate-500">Sin datos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            @if($player->mapStats->count() > 4)
                <button type="button" onclick="document.getElementById('map-stats-modal').classList.remove('hidden')"
                    class="mt-2 text-xs text-cyan-400 hover:underline">
                    Ver todos los mapas ({{ $player->mapStats->count() }}) →
                </button>
            @endif
        </section>

        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Alias usados</h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @forelse($player->aliases->take(5) as $alias)
                    <div class="px-4 py-2 flex items-center justify-between text-sm">
                        <span>{!! \App\Support\Cod2Colors::toHtml($alias->name) !!}</span>
                        <span class="text-xs text-slate-500">{{ $alias->last_seen_at->diffForHumans() }}</span>
                    </div>
                @empty
                    <div class="px-4 py-4 text-center text-slate-500 text-sm">Sin alias registrados.</div>
                @endforelse
            </div>
            @if($player->aliases->count() > 5)
                <button type="button" onclick="document.getElementById('alias-modal').classList.remove('hidden')"
                    class="mt-2 text-xs text-cyan-400 hover:underline">
                    Ver todos los alias ({{ $player->aliases->count() }}) →
                </button>
            @endif
        </section>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Historial de partidas</h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @forelse($matchHistory->take(5) as $h)
                    <a href="{{ route('matches.show', $h->match->id) }}" class="px-3 py-1.5 flex items-center justify-between text-xs hover:bg-slate-800/30">
                        <span class="flex items-center gap-1.5 min-w-0">
                            <span class="px-1 py-0.5 rounded text-[9px] font-medium shrink-0 {{ $h->won ? 'bg-emerald-950 text-emerald-400 border border-emerald-900' : 'bg-red-950 text-red-400 border border-red-900' }}">{{ $h->won ? 'G' : 'P' }}</span>
                            <span class="truncate">{{ \App\Support\MapCatalog::mapLabel($h->match->map) }}</span>
                        </span>
                        <span class="text-slate-500 shrink-0 ml-2">{{ $h->match->started_at->diffForHumans(null, null, true) }}</span>
                    </a>
                @empty
                    <div class="px-4 py-4 text-center text-slate-500 text-sm">Sin partidas con resultado registrado todavía.</div>
                @endforelse
            </div>
            @if($matchHistory->count() > 5)
                <button type="button" onclick="document.getElementById('match-history-modal').classList.remove('hidden')"
                    class="mt-2 text-xs text-cyan-400 hover:underline">
                    Ver todo el historial ({{ $matchHistory->count() }}) →
                </button>
            @endif
        </section>

        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Mapas ganados</h2>
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">Mapa</th>
                            <th class="px-4 py-2 font-medium text-right">Jugadas</th>
                            <th class="px-4 py-2 font-medium text-right">Ganadas</th>
                            <th class="px-4 py-2 font-medium text-right">Win rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($mapsWon->take(5) as $m)
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{{ \App\Support\MapCatalog::mapLabel($m->map) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $m->played }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-emerald-400">{{ $m->wins }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">{{ $m->rate }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">Sin partidas con resultado registrado todavía.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            @if($mapsWon->count() > 5)
                <button type="button" onclick="document.getElementById('maps-won-modal').classList.remove('hidden')"
                    class="mt-2 text-xs text-cyan-400 hover:underline">
                    Ver todos los mapas ({{ $mapsWon->count() }}) →
                </button>
            @endif
        </section>
    </div>

    <section>
        <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Armas</h2>
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">Arma</th>
                        <th class="px-4 py-2 font-medium text-right">Kills</th>
                        <th class="px-4 py-2 font-medium text-right">Headshots</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($weaponBreakdown->take(5) as $w)
                        <tr class="border-b border-slate-800/60 last:border-0">
                            <td class="px-4 py-2">{{ \App\Support\WeaponCatalog::label($w->weapon) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300">{{ $w->kills }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $w->headshots }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-4 text-center text-slate-500">Sin datos.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        @if($weaponBreakdown->count() > 5)
            <button type="button" onclick="document.getElementById('weapon-modal').classList.remove('hidden')"
                class="mt-2 text-xs text-cyan-400 hover:underline">
                Ver todas las armas ({{ $weaponBreakdown->count() }}) →
            </button>
        @endif
    </section>
</div>

@if($player->mapStats->count() > 4)
    <div id="map-stats-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-md max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">Todos los mapas ({{ $player->mapStats->count() }})</span>
                <button type="button" onclick="document.getElementById('map-stats-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">Mapa</th>
                            <th class="px-4 py-2 font-medium text-right">Kills</th>
                            <th class="px-4 py-2 font-medium text-right">Muertes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($player->mapStats as $stat)
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
                <span class="text-sm font-semibold">Todos los alias ({{ $player->aliases->count() }})</span>
                <button type="button" onclick="document.getElementById('alias-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto divide-y divide-slate-800/60">
                @foreach($player->aliases as $alias)
                    <div class="px-4 py-2 flex items-center justify-between text-sm">
                        <span>{!! \App\Support\Cod2Colors::toHtml($alias->name) !!}</span>
                        <span class="text-xs text-slate-500 shrink-0 ml-3">{{ $alias->last_seen_at->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if($mapsWon->count() > 5)
    <div id="maps-won-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-md max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">Todos los mapas ({{ $mapsWon->count() }})</span>
                <button type="button" onclick="document.getElementById('maps-won-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">Mapa</th>
                            <th class="px-4 py-2 font-medium text-right">Jugadas</th>
                            <th class="px-4 py-2 font-medium text-right">Ganadas</th>
                            <th class="px-4 py-2 font-medium text-right">Win rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mapsWon as $m)
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{{ \App\Support\MapCatalog::mapLabel($m->map) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $m->played }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-emerald-400">{{ $m->wins }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">{{ $m->rate }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if($matchHistory->count() > 5)
    <div id="match-history-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-md max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">Todo el historial ({{ $matchHistory->count() }})</span>
                <button type="button" onclick="document.getElementById('match-history-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto divide-y divide-slate-800/60">
                @foreach($matchHistory as $h)
                    <a href="{{ route('matches.show', $h->match->id) }}" class="px-4 py-2.5 flex items-center justify-between text-sm hover:bg-slate-800/30">
                        <span class="flex items-center gap-2">
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-medium {{ $h->won ? 'bg-emerald-950 text-emerald-400 border border-emerald-900' : 'bg-red-950 text-red-400 border border-red-900' }}">{{ $h->won ? 'Ganada' : 'Perdida' }}</span>
                            <span>{{ \App\Support\MapCatalog::mapLabel($h->match->map) }}</span>
                        </span>
                        <span class="text-xs text-slate-500 shrink-0 ml-3">{{ $h->match->started_at->diffForHumans() }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if($weaponBreakdown->count() > 5)
    <div id="weapon-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
        onclick="if(event.target === this) this.classList.add('hidden')">
        <div class="w-full max-w-md max-h-[80vh] flex flex-col rounded-xl border border-slate-800 bg-panel">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold">Todas las armas ({{ $weaponBreakdown->count() }})</span>
                <button type="button" onclick="document.getElementById('weapon-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">Arma</th>
                            <th class="px-4 py-2 font-medium text-right">Kills</th>
                            <th class="px-4 py-2 font-medium text-right">Headshots</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($weaponBreakdown as $w)
                            <tr class="border-b border-slate-800/60 last:border-0">
                                <td class="px-4 py-2">{{ \App\Support\WeaponCatalog::label($w->weapon) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">{{ $w->kills }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $w->headshots }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
