@php
    // Estado del visitante frente a este pug: de que equipo es capitan (si lo es) y
    // si le toca banear ahora. Se resuelve una sola vez aca en vez de repetir la
    // consulta en cada boton del veto.
    $me = auth('site')->user();
    $myTeam = $pug?->captainTeamFor($me);
    $turn = $pug?->currentTurnTeam();
@endphp

@if(!$pug)
    @if($teamBalance && $teamBalance->enough)
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-slate-200">{{ __('¿Arrancan a jugar?') }}</div>
                <p class="text-xs text-slate-500 mt-0.5">
                    {{ __('Abrir un pug congela estos equipos, habilita el veto de mapas y agrupa todas las partidas de la noche.') }}
                </p>
            </div>
            <form method="POST" action="{{ route('pugs.start') }}">
                @csrf
                <input type="hidden" name="server" value="{{ $server->slug }}">
                <button type="submit" class="px-4 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold whitespace-nowrap">
                    🎯 {{ __('Iniciar pug') }}
                </button>
            </form>
        </div>
    @endif
@else
    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex flex-wrap items-center justify-between gap-2">
            <div class="text-sm font-semibold flex items-center gap-2">
                🎯 {{ __('Pug en curso') }}
                <span class="text-[11px] font-normal text-slate-500">{{ $pug->started_at?->format('d/m/Y H:i') }}</span>
            </div>
            @if($myTeam)
                <form method="POST" action="{{ route('pugs.close', $pug) }}" onsubmit="return confirm('{{ __('¿Cerrar el pug? Las partidas que se jueguen después ya no van a quedar agrupadas acá.') }}')">
                    @csrf
                    <button type="submit" class="text-xs text-slate-500 hover:text-red-400">{{ __('Cerrar pug') }}</button>
                </form>
            @endif
        </div>

        @if($pug->status === \App\Models\Pug::STATUS_AWAITING_CAPTAINS)
            <div class="p-4 space-y-3">
                <p class="text-xs text-slate-500">
                    {{ __('Falta que se postule un capitán por equipo. El primero de cada lado se queda con el rol — tenés que estar logueado y haber reclamado tu perfil.') }}
                </p>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach(['A' => 'text-red-400', 'B' => 'text-blue-400'] as $team => $color)
                        @php $captain = $team === 'A' ? $pug->teamACaptain : $pug->teamBCaptain; @endphp
                        <div class="rounded-lg border border-slate-800 bg-panel2 p-3">
                            <div class="text-[11px] uppercase tracking-wide {{ $color }} font-semibold mb-2">{{ __('Equipo') }} {{ $team }}</div>
                            <ul class="text-xs text-slate-400 space-y-0.5 mb-3">
                                @foreach($pug->teams[$team] ?? [] as $player)
                                    <li>{!! \App\Support\Cod2Colors::toHtml($player['name']) !!}</li>
                                @endforeach
                            </ul>
                            @if($captain)
                                <div class="text-xs text-emerald-400">👑 {{ $captain->discord_username }}</div>
                            @elseif($me)
                                <form method="POST" action="{{ route('pugs.claim-captain', $pug) }}">
                                    @csrf
                                    <input type="hidden" name="team" value="{{ $team }}">
                                    <button type="submit" class="w-full px-3 py-1.5 rounded-lg border border-slate-700 text-xs text-slate-300 hover:border-cyan-500 hover:text-cyan-400">
                                        {{ __('Ser capitán') }}
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('login') }}" class="text-xs text-cyan-400 hover:underline">{{ __('Iniciá sesión para ser capitán') }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

        @elseif($pug->status === \App\Models\Pug::STATUS_VETO)
            <div class="p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-xs text-slate-400">
                        {{ __('Quedan') }} <span class="text-slate-200 font-semibold">{{ count($pug->remainingMaps()) }}</span>
                        {{ __('mapas · se banea hasta quedar') }} <span class="text-slate-200 font-semibold">{{ $pug->targetMapCount() }}</span>
                    </div>
                    <div class="text-xs {{ $turn === $myTeam ? 'text-emerald-400 font-semibold' : 'text-slate-500' }}">
                        {{ $turn === $myTeam ? __('Es tu turno de banear') : __('Turno del equipo :team', ['team' => $turn]) }}
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach($pug->veto_pool ?? [] as $code)
                        @php
                            $isBanned = in_array($code, array_column($pug->veto_bans ?? [], 'map'), true);
                            $canBan = !$isBanned && $myTeam && $turn === $myTeam;
                        @endphp
                        <form method="POST" action="{{ route('pugs.ban', $pug) }}">
                            @csrf
                            <input type="hidden" name="map" value="{{ $code }}">
                            <button type="submit" @disabled(!$canBan)
                                class="w-full px-3 py-2 rounded-lg border text-xs text-left transition-colors
                                {{ $isBanned
                                    ? 'border-slate-800 bg-slate-900/60 text-slate-600 line-through'
                                    : ($canBan ? 'border-slate-700 text-slate-200 hover:border-red-500 hover:text-red-400' : 'border-slate-800 text-slate-500') }}">
                                {{ \App\Support\MapCatalog::mapLabel($code) }}
                            </button>
                        </form>
                    @endforeach
                </div>

                @if($pug->veto_bans)
                    <div class="text-[11px] text-slate-600">
                        {{ __('Baneos:') }}
                        {{ collect($pug->veto_bans)->map(fn ($b) => $b['team'].' → '.\App\Support\MapCatalog::mapLabel($b['map']))->implode(' · ') }}
                    </div>
                @endif
            </div>

            {{-- Refresco simple mientras no es tu turno: el otro capitan puede banear
                 en cualquier momento y no hay push, igual que el resto del sitio. --}}
            @if($turn !== $myTeam)
                <script>setTimeout(function () { window.location.reload(); }, 6000);</script>
            @endif

        @else
            @php $score = $pug->scoreboard(); @endphp
            <div class="p-4 space-y-4">
                <div class="flex items-center justify-center gap-4 text-center">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-red-400 font-semibold">{{ __('Equipo A') }}</div>
                        <div class="text-3xl font-semibold tabular-nums">{{ $score['A'] }}</div>
                    </div>
                    <div class="text-slate-600 text-xl">—</div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-blue-400 font-semibold">{{ __('Equipo B') }}</div>
                        <div class="text-3xl font-semibold tabular-nums">{{ $score['B'] }}</div>
                    </div>
                </div>

                <div>
                    <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-2">{{ __('Mapas de la noche') }}</div>
                    <ol class="space-y-1">
                        @foreach($pug->maps ?? [] as $i => $code)
                            <li class="flex items-center gap-2 text-sm {{ $i === $pug->current_map_index ? 'text-cyan-400 font-semibold' : ($i < $pug->current_map_index ? 'text-slate-600' : 'text-slate-300') }}">
                                <span class="tabular-nums text-slate-600">{{ $i + 1 }}.</span>
                                {{ \App\Support\MapCatalog::mapLabel($code) }}
                                @if($i === $pug->current_map_index)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-cyan-950 border border-cyan-800">{{ __('jugando') }}</span>
                                @elseif($i < $pug->current_map_index)
                                    <span class="text-[10px] text-slate-600">{{ __('jugado') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>

                @if($pug->matches()->exists())
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-2">{{ __('Partidas de este pug') }}</div>
                        <ul class="space-y-1 text-sm">
                            @foreach($pug->matches()->latest('started_at')->get() as $match)
                                <li>
                                    <a href="{{ route('matches.show', $match) }}" class="text-slate-300 hover:text-cyan-400">
                                        {{ \App\Support\MapCatalog::mapLabel($match->map) }}
                                        <span class="text-slate-600 text-xs">{{ $match->started_at?->format('H:i') }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endif
