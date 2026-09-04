{{-- Espera $teamBalance en scope (TeamBalancer::suggest(), o null si el
     server no respondio por RCON) -- compartido entre admin/console.blade.php
     y team-balance.blade.php (pagina publica /equipos) para no duplicar el
     marcado. Ver CLAUDE.md, "Balanceador de equipos por rango".

     $discordNotifyAction (opcional) es la URL a la que postea el boton
     "Notificar Discord" -- si se omite, el boton no se muestra.
     admin/console.blade.php postea a admin.console.notify-teams (auth admin);
     team-balance.blade.php (pagina publica /equipos) postea a
     team-balance.notify, sin auth -- a pedido explicito del dueño, cualquier
     visitante puede notificar los equipos armados, no solo un admin.

     $mantenerActive (opcional, bool) -- el estado REAL de "mantener
     asignaciones anteriores" ya resuelto por el controller
     (TeamBalancer::shouldPreserve(), 2026-09-04), no la query cruda: la
     consola admin recalcula en cada carga de página y por default respeta
     lo guardado aunque el link no traiga "?mantener=1" explícito, así que
     leer request()->boolean('mantener') acá directo mostraría el candado
     abierto aunque en los hechos SÍ se haya preservado. Si no se pasa,
     cae a la query cruda (compatibilidad con cualquier otro include
     futuro que no la calcule). --}}
<div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
    @php
        $mantener = $mantenerActive ?? request()->boolean('mantener');
    @endphp
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3 flex-wrap">
        <span class="text-xs uppercase tracking-wide text-slate-400">{{ __('Balanceo sugerido de equipos') }}</span>
        <div class="flex items-center gap-3 flex-wrap">
            <a href="{{ request()->fullUrlWithQuery(['mantener' => $mantener ? 0 : 1]) }}"
                class="text-[11px] inline-flex items-center gap-1 {{ $mantener ? 'text-emerald-400' : 'text-slate-500 hover:text-slate-300' }}"
                title="{{ __('Si está activado, los jugadores que ya quedaron asignados no se mueven al regenerar — solo reparte a los que se conectaron después entre los dos equipos.') }}">
                {{ $mantener ? '🔒' : '🔓' }} {{ __('Mantener asignaciones anteriores') }}
            </a>
            @if($teamBalance && $teamBalance->enough)
                <span class="text-[11px] text-slate-500">{{ __('Score total') }}: <span class="text-cyan-400 font-medium">{{ $teamBalance->scoreA }}</span> vs <span class="text-cyan-400 font-medium">{{ $teamBalance->scoreB }}</span></span>
            @endif
            @if(isset($discordNotifyAction) && $teamBalance && $teamBalance->enough)
                <form method="POST" action="{{ $discordNotifyAction }}" onsubmit="return confirm('¿Notificar estos equipos al canal de Discord?')">
                    @csrf
                    <input type="hidden" name="mantener" value="{{ $mantener ? 1 : 0 }}">
                    @foreach($discordNotifyFields ?? [] as $field => $value)
                        <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                    @endforeach
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-[#5865F2]/15 border border-[#5865F2]/40 text-[#5865F2] hover:bg-[#5865F2]/25 px-3 py-1.5 text-xs font-semibold whitespace-nowrap">📣 {{ __('Notificar Discord') }}</button>
                </form>
            @endif
        </div>
    </div>
    <div class="p-4">
        @if(!$teamBalance)
            <p class="text-sm text-slate-500">{{ __('No se pudo calcular — el servidor no respondió por RCON.') }}</p>
        @elseif(!$teamBalance->enough)
            <p class="text-sm text-slate-500">
                {{ __('Se necesitan al menos :n jugadores reales conectados (mínimo 2 por equipo) para sugerir un balance.', ['n' => \App\Support\TeamBalancer::MIN_PLAYERS]) }}
                {{ __('Ahora mismo hay :eligible.', ['eligible' => $teamBalance->eligible.($teamBalance->bots ? ' (+'.$teamBalance->bots.' bot'.($teamBalance->bots > 1 ? 's' : '').')' : '')]) }}
            </p>
        @else
            @if($teamBalance->bots)
                <p class="text-xs text-slate-500 mb-3">{{ __(':n bot(s) conectado(s) — no se incluyen en el balanceo.', ['n' => $teamBalance->bots]) }}</p>
            @endif
            @php
                // Iconos personalizados (2026-08-28), batcheado una sola vez para
                // los dos equipos en vez de una query por jugador.
                $balanceGuids = $teamBalance->teamA->pluck('guid')->merge($teamBalance->teamB->pluck('guid'))->filter()->values();
                $balanceIconByGuid = $balanceGuids->isNotEmpty()
                    ? \App\Models\Player::whereIn('guid', $balanceGuids)->whereNotNull('icon_path')->get(['guid', 'icon_path'])->keyBy('guid')
                    : collect();
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach(['A' => $teamBalance->teamA, 'B' => $teamBalance->teamB] as $label => $team)
                    <div class="rounded-lg border border-slate-800">
                        <div class="px-3 py-2 border-b border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-300">{{ __('Equipo :label', ['label' => $label]) }}</span>
                            <span class="text-[11px] text-slate-500">{{ __(':n jugador(es)', ['n' => $team->count()]) }}</span>
                        </div>
                        <ul class="divide-y divide-slate-800/60">
                            @foreach($team as $p)
                                @php
                                    $tierStyle = match($p->rango) {
                                        'S' => 'bg-fuchsia-950/40 border-fuchsia-600 text-fuchsia-300',
                                        'A' => 'bg-amber-950/40 border-amber-700 text-amber-300',
                                        'B' => 'bg-cyan-950/40 border-cyan-700 text-cyan-300',
                                        'C' => 'bg-orange-950/40 border-orange-800 text-orange-400',
                                        'D' => 'bg-red-950/40 border-red-900 text-red-400',
                                        default => 'bg-slate-800/40 border-slate-700 text-slate-500',
                                    };
                                @endphp
                                <li class="px-3 py-2 flex items-center justify-between gap-2 text-sm">
                                    <span class="min-w-0 truncate">{!! \App\Support\Cod2Colors::toHtml($p->name) !!} <x-player-icon :player="$balanceIconByGuid[$p->guid] ?? null" /></span>
                                    <span class="inline-flex items-center justify-center w-6 h-6 shrink-0 rounded border text-[11px] font-bold {{ $tierStyle }}" title="{{ $p->rango ? __('Rango :r', ['r' => $p->rango]) : __('Sin rango suficiente') }}">{{ $p->rango ?? '?' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <p class="text-[11px] text-slate-500 mt-3">
                {!! __('Solo es una sugerencia — el cambio de equipo se hace a mano en el juego (:axis/:allies).', ['axis' => '<code class="font-mono">team axis</code>', 'allies' => '<code class="font-mono">team allies</code>']) !!}
                {{ __('"?" significa que ese jugador todavía no tiene suficientes partidas en este server para un rango (mínimo :n partidas).', ['n' => \App\Support\PlayerRankCalculator::MIN_MATCHES]) }}
            </p>
        @endif
    </div>
</div>
