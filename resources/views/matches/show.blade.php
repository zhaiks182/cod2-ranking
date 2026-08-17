@extends('layouts.app')

@section('title', \App\Support\MapCatalog::mapLabel($match->map))

@section('content')
<div class="space-y-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <div>
        <a href="{{ route('matches.index', ['server' => $match->server?->slug]) }}" class="text-xs text-slate-500 hover:text-slate-300">← Volver a partidas</a>
        <h1 class="text-lg font-semibold mt-1">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            {{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }} ·
            @if($match->is_backfilled)
                fecha no disponible (importado)
            @else
                {{ $match->started_at->translatedFormat('j \d\e F, Y') }} ·
                {{ $match->started_at->format('H:i') }}@if($match->ended_at) – {{ $match->ended_at->format('H:i') }}@endif ·
                {{ $match->duration_label }}
                @if($match->ended_at)
                    <span class="text-emerald-400">Finalizado</span>
                @else
                    <span class="text-emerald-400">(en curso)</span>
                @endif
            @endif
            @if($finalScore)
                · <span class="text-slate-300 font-medium">{{ $finalScore }}</span>
            @else
                · {{ $rounds->count() }} ronda(s)
            @endif
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        @if($chatMessages->isNotEmpty())
          <button type="button" onclick="document.getElementById('cod2-chat-modal').classList.remove('hidden')"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-sm text-slate-300 hover:border-cyan-500 hover:text-cyan-400">
              💬 Chats General <span class="text-slate-500">({{ $chatMessages->count() }})</span>
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

    @if($matchStartKill || $halftimeKill || $overtimeEvent || $timeoutEvents->isNotEmpty() || $topBash || $topHeadshots || $topGrenades)
        <div class="flex flex-wrap gap-2 text-xs">
            @if($topBash)
                <span class="px-2.5 py-1.5 rounded-lg border border-orange-900 bg-orange-950/40 text-orange-300">
                    👊 Más bash ·
                    {!! \App\Support\Cod2Colors::toHtml($topBash->player->last_name) !!}
                    ({{ $topBash->bash }})
                </span>
            @endif
            @if($topHeadshots)
                <span class="px-2.5 py-1.5 rounded-lg border border-sky-900 bg-sky-950/40 text-sky-300">
                    🎯 Más headshots ·
                    {!! \App\Support\Cod2Colors::toHtml($topHeadshots->player->last_name) !!}
                    ({{ $topHeadshots->headshots }})
                </span>
            @endif
            @if($topGrenades)
                <span class="px-2.5 py-1.5 rounded-lg border border-lime-900 bg-lime-950/40 text-lime-300">
                    💣 Más granadas ·
                    {!! \App\Support\Cod2Colors::toHtml($topGrenades->player->last_name) !!}
                    ({{ $topGrenades->grenade_kills }})
                </span>
            @endif
            @if($matchStartKill)
                <span class="px-2.5 py-1.5 rounded-lg border border-emerald-900 bg-emerald-950/40 text-emerald-300">
                    Inicio ·
                    {{ \App\Support\Cod2Colors::stripColors($matchStartKill->attacker_name) }}
                    →
                    {{ \App\Support\Cod2Colors::stripColors($matchStartKill->victim_name) }}
                    ({{ \App\Support\WeaponCatalog::label($matchStartKill->weapon) }})
                </span>
            @endif
            @if($halftimeKill)
                <span class="px-2.5 py-1.5 rounded-lg border border-amber-900 bg-amber-950/40 text-amber-300">
                    Cambio de bando ·
                    {{ \App\Support\Cod2Colors::stripColors($halftimeKill->attacker_name) }}
                    →
                    {{ \App\Support\Cod2Colors::stripColors($halftimeKill->victim_name) }}
                    ({{ \App\Support\WeaponCatalog::label($halftimeKill->weapon) }})
                </span>
            @endif
            @if($overtimeEvent)
                <span class="px-2.5 py-1.5 rounded-lg border border-fuchsia-900 bg-fuchsia-950/40 text-fuchsia-300">⏱️ Tiempo extra</span>
            @endif
            @foreach($timeoutEvents as $ev)
                <span class="px-2.5 py-1.5 rounded-lg border border-slate-700 bg-slate-800/60 text-slate-300">
                    @if($ev->event_type === 'timeout_call')
                        ⏸️ Timeout · {{ \App\Support\Cod2Colors::stripColors($ev->name) }} ({{ ucfirst($ev->side) }})
                    @elseif($ev->event_type === 'timeout_cancel')
                        ▶️ Timeout cancelado · {{ \App\Support\Cod2Colors::stripColors($ev->name) }}
                    @elseif($ev->event_type === 'bash_call')
                        🥊 Bash · {{ \App\Support\Cod2Colors::stripColors($ev->name) }} ({{ ucfirst($ev->side) }})
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    @php $tkParams = http_build_query(['match' => $match->id]); @endphp
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
                @forelse($leaderboard as $i => $row)
                    @php $kd = $row->deaths > 0 ? round($row->kills / $row->deaths, 2) : $row->kills; $country = \App\Services\GeoIp::countryFor($row->player->ip); @endphp
                    <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                        <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                        <td class="px-4 py-2 font-medium">
                            @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                            <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
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
                        <td class="px-4 py-2 text-right tabular-nums">{{ $row->headshots }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $row->grenade_kills }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Sin bajas registradas en esta partida.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($axisRows->isNotEmpty() || $alliesRows->isNotEmpty())
        <div>
            <h2 class="text-sm uppercase tracking-wide text-slate-200 font-bold mb-3">Tabla de Posiciones</h2>
            <div class="grid md:grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-800 text-xs uppercase tracking-wide text-red-400 font-medium flex items-center gap-2">
                        Axis
                        @if($sideScores['axis'] !== null)
                            <span class="text-slate-400 normal-case">({{ $sideScores['axis'] }})</span>
                        @endif
                        @if($sideScores['winning'] === 'axis')
                            <span class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-900 text-[10px] normal-case tracking-normal">Ganador</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 font-medium">Jugador</th>
                                <th class="px-4 py-2 font-medium text-right">Kills</th>
                                <th class="px-4 py-2 font-medium text-right">Muertes</th>
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
                                <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">Sin datos.</td></tr>
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
                            <span class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-900 text-[10px] normal-case tracking-normal">Ganador</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 font-medium">Jugador</th>
                                <th class="px-4 py-2 font-medium text-right">Kills</th>
                                <th class="px-4 py-2 font-medium text-right">Muertes</th>
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
                                <tr><td colspan="4" class="px-4 py-4 text-center text-slate-500">Sin datos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@if($chatMessages->isNotEmpty())
    <div id="cod2-chat-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="w-full max-w-lg max-h-[80vh] rounded-xl border border-slate-800 bg-panel shadow-xl flex flex-col">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
                <span class="text-sm font-semibold flex items-center gap-2">💬 Chats de la partida</span>
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
                <span class="text-sm font-semibold flex items-center gap-2 text-red-400">💬 Chat de equipo · Axis</span>
                <button type="button" onclick="document.getElementById('cod2-chat-axis-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="px-4 py-3 overflow-y-auto space-y-1.5 text-sm">
                @foreach($axisChat as $msg)
                    <div class="flex gap-2">
                        <span class="text-slate-600 tabular-nums shrink-0">{{ $msg->occurred_at->format('H:i') }}</span>
                        <span>
                            @if($msg->player)
                                <a href="{{ route('players.show', $msg->player->guid) }}" class="font-medium hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($msg->player->last_name) !!}</a>
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
                <span class="text-sm font-semibold flex items-center gap-2 text-blue-400">💬 Chat de equipo · Allies</span>
                <button type="button" onclick="document.getElementById('cod2-chat-allies-modal').classList.add('hidden')" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div class="px-4 py-3 overflow-y-auto space-y-1.5 text-sm">
                @foreach($alliesChat as $msg)
                    <div class="flex gap-2">
                        <span class="text-slate-600 tabular-nums shrink-0">{{ $msg->occurred_at->format('H:i') }}</span>
                        <span>
                            @if($msg->player)
                                <a href="{{ route('players.show', $msg->player->guid) }}" class="font-medium hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($msg->player->last_name) !!}</a>
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
