{{-- Espera $teamBalance en scope (TeamBalancer::suggest(), o null si el
     server no respondio por RCON) -- compartido entre admin/console.blade.php
     y partials/live-status.blade.php (pagina publica) para no duplicar el
     marcado. Ver CLAUDE.md, "Balanceador de equipos por rango". --}}
<div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
        <span class="text-xs uppercase tracking-wide text-slate-400">Balanceo sugerido de equipos</span>
        @if($teamBalance && $teamBalance->enough)
            <span class="text-[11px] text-slate-500">Score total: <span class="text-cyan-400 font-medium">{{ $teamBalance->scoreA }}</span> vs <span class="text-cyan-400 font-medium">{{ $teamBalance->scoreB }}</span></span>
        @endif
    </div>
    <div class="p-4">
        @if(!$teamBalance)
            <p class="text-sm text-slate-500">No se pudo calcular — el servidor no respondió por RCON.</p>
        @elseif(!$teamBalance->enough)
            <p class="text-sm text-slate-500">
                Se necesitan al menos {{ \App\Support\TeamBalancer::MIN_PLAYERS }} jugadores reales conectados (mínimo 2 por equipo) para sugerir un balance.
                Ahora mismo hay {{ $teamBalance->eligible }}{{ $teamBalance->bots ? ' (+'.$teamBalance->bots.' bot'.($teamBalance->bots > 1 ? 's' : '').')' : '' }}.
            </p>
        @else
            @if($teamBalance->bots)
                <p class="text-xs text-slate-500 mb-3">{{ $teamBalance->bots }} bot(s) conectado(s) — no se incluyen en el balanceo.</p>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach(['A' => $teamBalance->teamA, 'B' => $teamBalance->teamB] as $label => $team)
                    <div class="rounded-lg border border-slate-800">
                        <div class="px-3 py-2 border-b border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-300">Equipo {{ $label }}</span>
                            <span class="text-[11px] text-slate-500">{{ $team->count() }} jugador(es)</span>
                        </div>
                        <ul class="divide-y divide-slate-800/60">
                            @foreach($team as $p)
                                @php
                                    $tierStyle = match($p->rango) {
                                        'A' => 'bg-amber-950/40 border-amber-700 text-amber-300',
                                        'B' => 'bg-cyan-950/40 border-cyan-700 text-cyan-300',
                                        'C' => 'bg-slate-800/60 border-slate-600 text-slate-300',
                                        'D' => 'bg-orange-950/40 border-orange-800 text-orange-400',
                                        'E' => 'bg-red-950/40 border-red-900 text-red-400',
                                        default => 'bg-slate-800/40 border-slate-700 text-slate-500',
                                    };
                                @endphp
                                <li class="px-3 py-2 flex items-center justify-between gap-2 text-sm">
                                    <span class="min-w-0 truncate">{!! \App\Support\Cod2Colors::toHtml($p->name) !!}</span>
                                    <span class="inline-flex items-center justify-center w-6 h-6 shrink-0 rounded border text-[11px] font-bold {{ $tierStyle }}" title="{{ $p->rango ? 'Rango '.$p->rango : 'Sin rango suficiente' }}">{{ $p->rango ?? '?' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <p class="text-[11px] text-slate-500 mt-3">
                Solo es una sugerencia — el cambio de equipo se hace a mano en el juego (<code class="font-mono">team axis</code>/<code class="font-mono">team allies</code>).
                "?" significa que ese jugador todavía no tiene suficientes partidas en este server para un rango
                (mínimo {{ \App\Support\PlayerRankCalculator::MIN_MATCHES }} partidas).
            </p>
        @endif
    </div>
</div>
