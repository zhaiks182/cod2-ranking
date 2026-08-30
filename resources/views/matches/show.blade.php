@extends('layouts.app')

@section('title', \App\Support\MapCatalog::mapLabel($match->map))

@section('content')
<div class="space-y-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <div class="flex items-start gap-3">
        @if($mapImageUrl = \App\Support\MapImage::url($match->map))
            <img src="{{ $mapImageUrl }}" alt="" class="h-20 w-20 rounded-lg object-cover shrink-0">
        @endif
        <div>
            <a href="{{ route('matches.index', ['server' => $match->server?->slug]) }}" class="text-xs text-slate-500 hover:text-slate-300">← {{ __('Volver a partidas') }}</a>
            <h1 class="text-lg font-semibold mt-1">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                {{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }} ·
                @if($match->is_backfilled)
                    {{ __('fecha no disponible (importado)') }}
                @else
                    {{ $match->started_at->translatedFormat('j \d\e F, Y') }} ·
                    {{ $match->started_at->format('H:i') }}@if($match->ended_at) – {{ $match->ended_at->format('H:i') }}@endif ·
                    {{ $match->duration_label }}
                    @if($match->ended_at)
                        <span class="text-emerald-400">{{ __('Finalizado') }}</span>
                    @else
                        <span class="text-emerald-400">{{ __('(en curso)') }}</span>
                    @endif
                @endif
                @if($finalScore)
                    · <span class="text-slate-300 font-medium">{{ $finalScore }}</span>
                @else
                    · {{ __(':n ronda(s)', ['n' => $rounds->count()]) }}
                @endif
            </p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        @php
            $eventBadgeCount = ($matchStartKill ? 1 : 0) + ($halftimeKill ? 1 : 0) + ($topHeadshots ? 1 : 0)
                + ($topGrenades ? 1 : 0) + ($topBash ? 1 : 0) + ($overtimeEvent ? 1 : 0) + $timeoutEvents->count();
        @endphp
        @if($eventBadgeCount > 0)
          <button type="button" onclick="document.getElementById('cod2-events-modal').classList.remove('hidden')"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-sm text-slate-300 hover:border-cyan-500 hover:text-cyan-400">
              📋 {{ __('Eventos de la partida') }} <span class="text-slate-500">({{ $eventBadgeCount }})</span>
          </button>
        @endif
        @if($chatMessages->isNotEmpty())
          <button type="button" onclick="document.getElementById('cod2-chat-modal').classList.remove('hidden')"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-sm text-slate-300 hover:border-cyan-500 hover:text-cyan-400">
              💬 {{ __('Chats General') }} <span class="text-slate-500">({{ $chatMessages->count() }})</span>
          </button>
        @endif
        @if($axisChat->isNotEmpty())
          <button type="button" onclick="document.getElementById('cod2-chat-axis-modal').classList.remove('hidden')"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-900 text-sm text-red-400 hover:border-red-500">
              💬 Axis <span class="text-red-500/70">({{ $axisChat->count() }})</span>
          </button>
        @endif
        @if($alliesChat->isNotEmpty())
          <button type="button" onclick="document.getElementById('cod2-chat-allies-modal').classList.remove('hidden')"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-blue-900 text-sm text-blue-400 hover:border-blue-500">
              💬 Allies <span class="text-blue-500/70">({{ $alliesChat->count() }})</span>
          </button>
        @endif
      </div>
    </div>

    @if($roundDetails->isNotEmpty())
        <div>
            <h2 class="text-sm uppercase tracking-wide text-slate-200 font-bold mb-3">{{ __('Línea de tiempo') }}</h2>

            {{-- Un cuadrado por ronda, en orden, rojo si gano axis esa ronda especifica,
            azul si gano allies (mismos colores que los paneles Axis/Allies de mas
            abajo) -- el lado real DENTRO de esa ronda, no el lado "actual" de nadie
            (ver comentario de roundWinningSide() en el controller: los lados cambian
            de bando en el entretiempo). Termina con el ganador/perdedor del match
            entero (mismo $sideScores que ya usan los paneles de abajo). Cada
            cuadrado abre el popup de rondas (mas abajo en la pagina) directo en esa
            ronda -- ver data-round-jump en el script al final del archivo. --}}
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach($roundDetails as $rd)
                    <a href="#round-detail-{{ $rd->round->id }}" data-round-jump="round-detail-{{ $rd->round->id }}" data-round-number="{{ $rd->number }}" data-round-winner="{{ $rd->winningSide }}"
                        title="{{ __('Ronda :n', ['n' => $rd->number]) }}{{ $rd->winningSide === 'axis' ? __(' — Axis ganó') : ($rd->winningSide === 'allies' ? __(' — Allies ganó') : '') }}"
                        class="w-8 h-8 rounded-md flex items-center justify-center text-sm font-semibold
                            {{ match($rd->winningSide) { 'axis' => 'bg-red-900/70 text-red-300', 'allies' => 'bg-blue-900/70 text-blue-300', default => 'bg-slate-800 text-slate-500' } }}
                            hover:ring-2 hover:ring-cyan-400 cursor-pointer">
                        {{ $rd->number }}
                    </a>
                @endforeach
                @if($sideScores['winning'])
                    @php $loser = $sideScores['winning'] === 'axis' ? 'allies' : 'axis'; @endphp
                    <span class="ml-2 flex items-center gap-1.5 text-sm">
                        <span class="px-2.5 py-1.5 rounded-md {{ $sideScores['winning'] === 'axis' ? 'bg-red-950/60 border border-red-800 text-red-300' : 'bg-blue-950/60 border border-blue-800 text-blue-300' }} font-medium">
                            🏆 {{ ucfirst($sideScores['winning']) }} ({{ $sideScores[$sideScores['winning']] }})
                        </span>
                        <span class="px-2.5 py-1.5 rounded-md bg-slate-800/60 border border-slate-700 text-slate-500">
                            {{ ucfirst($loser) }} ({{ $sideScores[$loser] }})
                        </span>
                    </span>
                @endif
            </div>
        </div>
    @endif

    @php $tkParams = http_build_query(['match' => $match->id]); @endphp

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
                                        <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
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
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $row->deaths }}</td>
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
                                        <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
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
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $row->deaths }}</td>
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

    <div>
        <h2 class="text-sm uppercase tracking-wide text-slate-200 font-bold mb-3">{{ __('Tabla General') }}</h2>
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
                    </tr>
                </thead>
                <tbody>
                    @forelse($leaderboard as $i => $row)
                        @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">
                                @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
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
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row->deaths }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $kd }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                <button type="button" data-headshots-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->headshots }}</button>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                <button type="button" data-grenades-trigger data-player="{{ $row->player->guid }}" data-params="{{ $tkParams }}" class="px-1 py-1.5 -my-1.5 hover:underline hover:text-cyan-200">{{ $row->grenade_kills }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">{{ __('Sin bajas registradas en esta partida.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

@if($roundDetails->isNotEmpty())
    <div id="cod2-rounds-modal" class="hidden fixed inset-0 z-50 flex items-start justify-center p-4 pt-16 bg-black/60" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="w-full max-w-3xl max-h-[calc(100vh-8rem)] rounded-xl border border-slate-800 bg-panel shadow-xl flex flex-col">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2">
                    <button type="button" id="cod2-round-prev" onclick="cod2StepRound(-1)" class="w-7 h-7 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-500 hover:text-cyan-400 disabled:opacity-30 disabled:pointer-events-none flex items-center justify-center" title="{{ __('Ronda anterior') }}">‹</button>
                    <span class="text-sm font-semibold">🎞️ <span id="cod2-rounds-modal-title">{{ __('Ronda') }}</span></span>
                    <span id="cod2-rounds-modal-winner" class="px-1.5 py-0.5 rounded text-[10px] font-medium hidden"></span>
                    <button type="button" id="cod2-round-next" onclick="cod2StepRound(1)" class="w-7 h-7 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-500 hover:text-cyan-400 disabled:opacity-30 disabled:pointer-events-none flex items-center justify-center" title="{{ __('Ronda siguiente') }}">›</button>
                </div>
                <button type="button" onclick="document.getElementById('cod2-rounds-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="overflow-y-auto flex-1 min-h-0">
                @foreach($roundDetails as $rd)
                    <div id="round-detail-{{ $rd->round->id }}" class="round-detail hidden border-b border-slate-800/60 last:border-0">
                        @if($rd->kills->isEmpty())
                            <div class="px-4 py-4 text-center text-sm text-slate-500">{{ __('Sin kills registradas en esta ronda.') }}</div>
                        @else
                            <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800/60">
                                        <th class="px-4 py-2 font-medium">{{ __('Hora') }}</th>
                                        <th class="px-4 py-2 font-medium">{{ __('Atacante') }}</th>
                                        <th class="px-4 py-2 font-medium"></th>
                                        <th class="px-4 py-2 font-medium">{{ __('Víctima') }}</th>
                                        <th class="px-4 py-2 font-medium">{{ __('Arma') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rd->kills as $kill)
                                        @php
                                            $survivorGuid = $rd->clutchGuid;
                                            $isClutchKill = $survivorGuid && $kill->attacker_guid === $survivorGuid;
                                        @endphp
                                        <tr class="border-b border-slate-800/40 last:border-0 {{ $isClutchKill ? 'bg-cyan-950/20' : '' }}">
                                            <td class="px-4 py-2 text-slate-500 text-xs tabular-nums">{{ $kill->occurred_at->format('H:i:s') }}</td>
                                            <td class="px-4 py-2 font-medium">
                                                @if($kill->attacker)
                                                    <a href="{{ route('players.show', $kill->attacker->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($kill->attacker_name) !!}</a>
                                                    <x-player-icon :player="$kill->attacker" />
                                                @else
                                                    <span class="text-slate-500">{{ \App\Support\Cod2Colors::stripColors($kill->attacker_name) }}</span>
                                                @endif
                                                @if($isClutchKill)<span title="{{ __('Clutch 1vX') }}">🥶</span>@endif
                                            </td>
                                            <td class="px-4 py-2 text-slate-600">{{ $kill->is_suicide ? '☠️' : '→' }}</td>
                                            <td class="px-4 py-2">
                                                @if($kill->victim)
                                                    <a href="{{ route('players.show', $kill->victim->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($kill->victim_name) !!}</a>
                                                    <x-player-icon :player="$kill->victim" />
                                                @else
                                                    <span class="text-slate-500">{{ \App\Support\Cod2Colors::stripColors($kill->victim_name) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2 text-slate-400 flex items-center gap-1.5">
                                                {{ \App\Support\WeaponCatalog::label($kill->weapon) }}
                                                @if($kill->is_headshot)<span title="Headshot">🎯</span>@endif
                                                @if($kill->is_teamkill)<span class="text-red-500 text-xs" title="{{ __('Fuego amigo') }}">FF</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

<script>
    // Textos usados por cod2ShowRoundByIndex() mas abajo -- __() no corre en el
    // navegador, se resuelven server-side una sola vez.
    const cod2MatchI18n = {
        round: @json(__('Ronda')),
        axisWon: @json(__('Axis ganó')),
        alliesWon: @json(__('Allies ganó')),
    };

    // Cada cuadradito de la linea de tiempo abre el popup directo en esa ronda --
    // ya no hay lista de botones "Ronda N" adentro del popup (se saco a pedido del
    // dueño, 2026-08-28): la linea de tiempo de arriba ES el selector de rondas.
    // Botones ‹/› + flechas del teclado (2026-08-29, a pedido del dueño: antes
    // habia que cerrar el popup y buscar el siguiente cuadradito a mano) navegan
    // por el mismo orden de $roundDetails, sin volver a pedir nada al server.
    const cod2RoundLinks = Array.from(document.querySelectorAll('[data-round-jump]'));
    let cod2CurrentRoundIdx = -1;

    function cod2ShowRoundByIndex(idx) {
        if (idx < 0 || idx >= cod2RoundLinks.length) return;

        const link = cod2RoundLinks[idx];
        const target = document.getElementById(link.dataset.roundJump);
        if (!target) return;

        document.querySelectorAll('.round-detail').forEach((d) => d.classList.add('hidden'));
        target.classList.remove('hidden');
        document.getElementById('cod2-rounds-modal-title').textContent = cod2MatchI18n.round + ' ' + link.dataset.roundNumber;

        const winnerEl = document.getElementById('cod2-rounds-modal-winner');
        const winner = link.dataset.roundWinner;
        if (winner === 'axis' || winner === 'allies') {
            winnerEl.textContent = winner === 'axis' ? cod2MatchI18n.axisWon : cod2MatchI18n.alliesWon;
            winnerEl.className = 'px-1.5 py-0.5 rounded text-[10px] font-medium ' + (winner === 'axis' ? 'bg-red-950/60 border border-red-800 text-red-300' : 'bg-blue-950/60 border border-blue-800 text-blue-300');
        } else {
            winnerEl.className = 'px-1.5 py-0.5 rounded text-[10px] font-medium hidden';
        }

        document.getElementById('cod2-rounds-modal')?.classList.remove('hidden');

        cod2CurrentRoundIdx = idx;
        document.getElementById('cod2-round-prev').disabled = idx <= 0;
        document.getElementById('cod2-round-next').disabled = idx >= cod2RoundLinks.length - 1;
    }

    function cod2StepRound(delta) {
        cod2ShowRoundByIndex(cod2CurrentRoundIdx + delta);
    }

    cod2RoundLinks.forEach((link, idx) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            cod2ShowRoundByIndex(idx);
        });
    });

    document.addEventListener('keydown', (e) => {
        const modal = document.getElementById('cod2-rounds-modal');
        if (!modal || modal.classList.contains('hidden')) return;
        if (e.key === 'ArrowLeft') cod2StepRound(-1);
        if (e.key === 'ArrowRight') cod2StepRound(1);
    });
</script>

@if($eventBadgeCount > 0)
    <div id="cod2-events-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="w-full max-w-2xl max-h-[80vh] rounded-xl border border-slate-800 bg-panel shadow-xl flex flex-col">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-base font-semibold flex items-center gap-2">📋 {{ __('Eventos de la partida') }}</span>
                <button type="button" onclick="document.getElementById('cod2-events-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300 text-lg leading-none">✕</button>
            </div>
            <div class="px-5 py-4 overflow-y-auto flex flex-col gap-2.5 text-sm">
                @if($matchStartKill)
                    <div class="px-3 py-2 rounded-lg border border-emerald-900 bg-emerald-950/40 text-emerald-300">
                        🏁 {{ __('Inicio de partida') }} ·
                        {{ \App\Support\Cod2Colors::stripColors($matchStartKill->attacker_name) }}
                        →
                        {{ \App\Support\Cod2Colors::stripColors($matchStartKill->victim_name) }}
                        ({{ \App\Support\WeaponCatalog::label($matchStartKill->weapon) }})
                    </div>
                @endif
                @if($halftimeKill)
                    <div class="px-3 py-2 rounded-lg border border-amber-900 bg-amber-950/40 text-amber-300">
                        🔄 {{ __('Cambio de bando') }} ·
                        {{ \App\Support\Cod2Colors::stripColors($halftimeKill->attacker_name) }}
                        →
                        {{ \App\Support\Cod2Colors::stripColors($halftimeKill->victim_name) }}
                        ({{ \App\Support\WeaponCatalog::label($halftimeKill->weapon) }})
                    </div>
                @endif
                @if($topHeadshots)
                    <div class="px-3 py-2 rounded-lg border border-sky-900 bg-sky-950/40 text-sky-300">
                        🎯 {{ __('Más headshots') }} ·
                        {!! \App\Support\Cod2Colors::toHtml($topHeadshots->player->last_name) !!}
                        <x-player-icon :player="$topHeadshots->player" />
                        ({{ $topHeadshots->headshots }})
                    </div>
                @endif
                @if($topGrenades)
                    <div class="px-3 py-2 rounded-lg border border-lime-900 bg-lime-950/40 text-lime-300">
                        💣 {{ __('Más granadas') }} ·
                        {!! \App\Support\Cod2Colors::toHtml($topGrenades->player->last_name) !!}
                        <x-player-icon :player="$topGrenades->player" />
                        ({{ $topGrenades->grenade_kills }})
                    </div>
                @endif
                @if($topBash)
                    <div class="px-3 py-2 rounded-lg border border-orange-900 bg-orange-950/40 text-orange-300">
                        👊 {{ __('Más bash') }} ·
                        {!! \App\Support\Cod2Colors::toHtml($topBash->player->last_name) !!}
                        <x-player-icon :player="$topBash->player" />
                        ({{ $topBash->bash }})
                    </div>
                @endif
                @if($overtimeEvent)
                    <div class="px-3 py-2 rounded-lg border border-fuchsia-900 bg-fuchsia-950/40 text-fuchsia-300">⏱️ {{ __('Tiempo extra') }}</div>
                @endif
                @foreach($timeoutEvents as $ev)
                    <div class="px-3 py-2 rounded-lg border border-slate-700 bg-slate-800/60 text-slate-300">
                        @if($ev->event_type === 'timeout_call')
                            ⏸️ Timeout · {{ \App\Support\Cod2Colors::stripColors($ev->name) }} ({{ ucfirst($ev->side) }})
                        @elseif($ev->event_type === 'timeout_cancel')
                            ▶️ {{ __('Timeout cancelado') }} · {{ \App\Support\Cod2Colors::stripColors($ev->name) }}
                        @elseif($ev->event_type === 'bash_call')
                            🥊 {{ __('Bash') }} · {{ \App\Support\Cod2Colors::stripColors($ev->name) }} ({{ ucfirst($ev->side) }})
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if($chatMessages->isNotEmpty())
    <div id="cod2-chat-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] rounded-xl border border-slate-800 bg-panel shadow-xl flex flex-col">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold flex items-center gap-2">💬 {{ __('Chats de la partida') }}</span>
                <button type="button" onclick="document.getElementById('cod2-chat-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="px-4 py-3 overflow-y-auto space-y-1.5 text-sm">
                @foreach($chatMessages as $msg)
                    <div class="flex gap-2">
                        <span class="text-slate-600 tabular-nums shrink-0">{{ $msg->occurred_at->format('H:i') }}</span>
                        <span>
                            @if($msg->player)
                                {{-- The say; log line's name field has no color codes (unlike Kill;/RCON status) —
                                     use the player's stored colored name instead of the plain one from the chat line. --}}
                                <a href="{{ route('players.show', $msg->player->guid) }}" class="font-medium hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($msg->player->last_name) !!}</a>
                                <x-player-icon :player="$msg->player" />
                            @else
                                <span class="font-medium">{!! \App\Support\Cod2Colors::toHtml($msg->name) !!}</span>
                            @endif
                            <span class="text-slate-500">:</span>
                            {!! \App\Support\Cod2Colors::toHtml($msg->message) !!}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if($axisChat->isNotEmpty())
    <div id="cod2-chat-axis-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] rounded-xl border border-slate-800 bg-panel shadow-xl flex flex-col">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold flex items-center gap-2 text-red-400">💬 {{ __('Chat de equipo') }} · Axis</span>
                <button type="button" onclick="document.getElementById('cod2-chat-axis-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="px-4 py-3 overflow-y-auto space-y-1.5 text-sm">
                @foreach($axisChat as $msg)
                    <div class="flex gap-2">
                        <span class="text-slate-600 tabular-nums shrink-0">{{ $msg->occurred_at->format('H:i') }}</span>
                        <span>
                            @if($msg->player)
                                <a href="{{ route('players.show', $msg->player->guid) }}" class="font-medium hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($msg->player->last_name) !!}</a>
                                <x-player-icon :player="$msg->player" />
                            @else
                                <span class="font-medium">{!! \App\Support\Cod2Colors::toHtml($msg->name) !!}</span>
                            @endif
                            <span class="text-slate-500">:</span>
                            {!! \App\Support\Cod2Colors::toHtml($msg->message) !!}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if($alliesChat->isNotEmpty())
    <div id="cod2-chat-allies-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] rounded-xl border border-slate-800 bg-panel shadow-xl flex flex-col">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold flex items-center gap-2 text-blue-400">💬 {{ __('Chat de equipo') }} · Allies</span>
                <button type="button" onclick="document.getElementById('cod2-chat-allies-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="px-4 py-3 overflow-y-auto space-y-1.5 text-sm">
                @foreach($alliesChat as $msg)
                    <div class="flex gap-2">
                        <span class="text-slate-600 tabular-nums shrink-0">{{ $msg->occurred_at->format('H:i') }}</span>
                        <span>
                            @if($msg->player)
                                <a href="{{ route('players.show', $msg->player->guid) }}" class="font-medium hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($msg->player->last_name) !!}</a>
                                <x-player-icon :player="$msg->player" />
                            @else
                                <span class="font-medium">{!! \App\Support\Cod2Colors::toHtml($msg->name) !!}</span>
                            @endif
                            <span class="text-slate-500">:</span>
                            {!! \App\Support\Cod2Colors::toHtml($msg->message) !!}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
@endsection
