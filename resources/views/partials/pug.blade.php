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

                {{-- Mismo selector visual que /servidores/crear: miniatura del mapa +
                     marco al pasar/elegir. Aca el "elegir" es banear, asi que el marco
                     de hover es rojo y el mapa baneado queda con la etiqueta encima. --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 p-0.5">
                    @foreach($pug->veto_pool ?? [] as $code)
                        @php
                            $isBanned = in_array($code, array_column($pug->veto_bans ?? [], 'map'), true);
                            $canBan = !$isBanned && $myTeam && $turn === $myTeam;
                            $mapImageUrl = \App\Support\MapImage::url($code);
                            $bannedBy = $isBanned
                                ? collect($pug->veto_bans)->firstWhere('map', $code)['team'] ?? null
                                : null;
                        @endphp
                        <form method="POST" action="{{ route('pugs.ban', $pug) }}"
                            @if($canBan) onsubmit="return confirm('{{ __('¿Banear') }} {{ \App\Support\MapCatalog::mapLabel($code) }}?')" @endif>
                            @csrf
                            <input type="hidden" name="map" value="{{ $code }}">
                            <button type="submit" @disabled(!$canBan)
                                class="group w-full flex flex-col items-start gap-1.5 rounded-lg border-2 overflow-hidden text-left transition-colors
                                {{ $isBanned
                                    ? 'border-red-900/70 bg-red-950/20'
                                    : ($canBan ? 'border-slate-700 hover:border-red-500 cursor-pointer' : 'border-slate-800') }}">
                                <span class="relative w-full aspect-video bg-panel2 flex items-center justify-center">
                                    @if($mapImageUrl)
                                        <img src="{{ $mapImageUrl }}" alt=""
                                            class="w-full h-full object-cover {{ $isBanned ? 'grayscale opacity-40' : ($canBan ? '' : 'opacity-70') }}">
                                    @else
                                        <svg class="w-9 h-9 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                                    @endif

                                    @if($isBanned)
                                        <span class="absolute inset-0 flex items-center justify-center">
                                            <span class="px-2 py-1 rounded bg-red-600/90 text-white text-[10px] font-bold uppercase tracking-widest">
                                                {{ __('Baneado') }}@if($bannedBy) · {{ $bannedBy }}@endif
                                            </span>
                                        </span>
                                    @elseif($canBan)
                                        <span class="absolute inset-0 items-center justify-center bg-red-950/70 hidden group-hover:flex">
                                            <span class="px-2 py-1 rounded bg-red-600 text-white text-[10px] font-bold uppercase tracking-widest">
                                                {{ __('Banear') }}
                                            </span>
                                        </span>
                                    @endif
                                </span>
                                <span class="px-2 pb-2 text-sm font-medium truncate w-full {{ $isBanned ? 'text-slate-600 line-through' : 'text-slate-200' }}">
                                    {{ \App\Support\MapCatalog::mapLabel($code) }}
                                </span>
                            </button>
                        </form>
                    @endforeach
                </div>
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
                    <ol class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 p-0.5">
                        @foreach($pug->maps ?? [] as $i => $code)
                            @php
                                $mapImageUrl = \App\Support\MapImage::url($code);
                                $isCurrent = $i === $pug->current_map_index;
                                $isPlayed = $i < $pug->current_map_index;
                            @endphp
                            <li class="rounded-lg border-2 overflow-hidden {{ $isCurrent ? 'border-cyan-400 ring-2 ring-cyan-500/40' : ($isPlayed ? 'border-slate-800' : 'border-slate-700') }}">
                                <span class="relative block w-full aspect-video bg-panel2 flex items-center justify-center">
                                    @if($mapImageUrl)
                                        <img src="{{ $mapImageUrl }}" alt="" class="w-full h-full object-cover {{ $isPlayed ? 'grayscale opacity-40' : '' }}">
                                    @else
                                        <svg class="w-9 h-9 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                                    @endif
                                    <span class="absolute top-1.5 left-1.5 w-5 h-5 rounded bg-black/70 text-white text-[11px] font-semibold tabular-nums flex items-center justify-center">{{ $i + 1 }}</span>
                                    @if($isCurrent)
                                        <span class="absolute bottom-1.5 right-1.5 px-1.5 py-0.5 rounded bg-cyan-600 text-white text-[10px] font-bold uppercase tracking-wide">{{ __('jugando') }}</span>
                                    @elseif($isPlayed)
                                        <span class="absolute bottom-1.5 right-1.5 px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 text-[10px] uppercase tracking-wide">{{ __('jugado') }}</span>
                                    @endif
                                </span>
                                <span class="block px-2 py-2 text-sm font-medium truncate {{ $isCurrent ? 'text-cyan-400 font-semibold' : ($isPlayed ? 'text-slate-600' : 'text-slate-200') }}">
                                    {{ \App\Support\MapCatalog::mapLabel($code) }}
                                </span>
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
