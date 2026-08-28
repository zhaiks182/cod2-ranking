@extends('layouts.app')

@section('title', $player->last_name_plain)

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-semibold">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</h1>
            <div class="text-[11px] font-mono text-slate-600 mt-0.5" title="Identificador único derivado del HWID del jugador">guid {{ $player->guid }}</div>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'player-season-dropdown',
            'seasonBaseRoute' => 'players.show',
            'seasonBaseParams' => [$player->guid],
        ])
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
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
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Últimas bajas</h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @forelse($recentKills as $kill)
                    <div class="px-4 py-2 text-sm flex items-center justify-between gap-2">
                        <span class="truncate flex items-center gap-2">
                            {!! \App\Support\Cod2Colors::toHtml($kill->victim_name) !!}
                            @if($kill->is_teamkill)
                                <span class="shrink-0 text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-amber-950 text-amber-400 border border-amber-900" title="Fuego amigo — mismo equipo">Fuego amigo</span>
                            @endif
                        </span>
                        <span class="text-xs text-slate-500 shrink-0">{{ \App\Support\WeaponCatalog::label($kill->weapon) }}</span>
                    </div>
                @empty
                    <div class="px-4 py-4 text-center text-slate-500 text-sm">Sin registros.</div>
                @endforelse
            </div>
        </section>

        <section>
            <h2 class="text-sm uppercase tracking-wide text-slate-500 mb-3">Últimas muertes</h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @forelse($recentDeaths as $death)
                    <div class="px-4 py-2 text-sm flex items-center justify-between gap-2">
                        <span class="truncate">
                            @if($death->is_suicide) suicidio
                            @else {!! \App\Support\Cod2Colors::toHtml($death->attacker_name) !!} @endif
                        </span>
                        <span class="text-xs text-slate-500 shrink-0">{{ \App\Support\WeaponCatalog::label($death->weapon) }}</span>
                    </div>
                @empty
                    <div class="px-4 py-4 text-center text-slate-500 text-sm">Sin registros.</div>
                @endforelse
            </div>
        </section>
    </div>
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
@endsection
