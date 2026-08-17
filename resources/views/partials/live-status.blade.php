@php
    $players = $status['players'] ?? [];
    usort($players, fn ($a, $b) => $b['score'] <=> $a['score']);
    $mapCode = $status['map'] ?? null;
    $maxClients = $server?->max_clients ?? 0;
    $connectString = trim(($server?->connect_ip ?? '').':'.($server?->connect_port ?? ''), ':');
    if ($server?->join_password) {
        $connectString .= '; password '.$server->join_password;
    }
    $mapLabel = \App\Support\MapCatalog::mapLabel($mapCode);
    $mapImageUrl = $mapCode ? \App\Support\MapImage::url($mapCode) : null;

    $gradients = [
        'from-cyan-800 to-slate-950', 'from-fuchsia-800 to-slate-950', 'from-amber-800 to-slate-950',
        'from-emerald-800 to-slate-950', 'from-rose-800 to-slate-950', 'from-indigo-800 to-slate-950',
    ];
    $gradient = $gradients[$mapCode ? crc32($mapCode) % count($gradients) : 0];

    // RCON "status" only reports score/ping in real time — deaths/headshots/grenades
    // are pulled from kills already parsed into the DB for the current match
    // ($latestMatch), so this reflects only the live/most-recent match in progress,
    // not each player's all-time total on this server. Batch-fetched once here
    // instead of a query per row.
    //
    // A "matches" row is only created on the first RoundStart; of a map (see
    // CLAUDE.md), not on map load — so right after a map change, $latestMatch still
    // points at the PREVIOUS map's (already-finished) match until that first round
    // starts. Score already resets to 0 on map load, so showing the old match's
    // deaths/headshots/grenades here would contradict that — only use $latestMatch
    // when its map actually matches what's live right now.
    $statsByGuid = [];
    $guids = collect($players)->pluck('guid')->filter(fn ($g) => $g !== 0)->values();
    $latestMatchIsCurrentMap = $latestMatch && $mapCode
        && \App\Support\MapCatalog::normalize($latestMatch->map) === \App\Support\MapCatalog::normalize($mapCode);
    if ($guids->isNotEmpty()) {
        $playerIdsByGuid = \App\Models\Player::whereIn('guid', $guids)->pluck('id', 'guid');
        $playerIds = $playerIdsByGuid->values();

        // No match for the current map yet (just changed, no RoundStart; seen so
        // far) — genuinely zero events so far, not "unknown", so default to 0 for
        // every connected real player rather than leaving them unset (which the
        // table would render as "—", implying no data rather than a fresh start).
        foreach ($playerIdsByGuid as $guid => $playerId) {
            $statsByGuid[$guid] = (object) ['deaths' => 0, 'headshots' => 0, 'grenade_kills' => 0];
        }

        if ($latestMatchIsCurrentMap) {
            $deaths = \App\Models\Kill::where('match_id', $latestMatch->id)
                ->whereIn('victim_player_id', $playerIds)
                ->selectRaw('victim_player_id, count(*) as c')->groupBy('victim_player_id')
                ->pluck('c', 'victim_player_id');

            $headshots = \App\Models\Kill::where('match_id', $latestMatch->id)
                ->whereIn('attacker_player_id', $playerIds)->where('is_headshot', true)
                ->selectRaw('attacker_player_id, count(*) as c')->groupBy('attacker_player_id')
                ->pluck('c', 'attacker_player_id');

            $grenades = \App\Models\Kill::where('match_id', $latestMatch->id)
                ->whereIn('attacker_player_id', $playerIds)->where('is_grenade', true)
                ->selectRaw('attacker_player_id, count(*) as c')->groupBy('attacker_player_id')
                ->pluck('c', 'attacker_player_id');

            foreach ($playerIdsByGuid as $guid => $playerId) {
                $statsByGuid[$guid] = (object) [
                    'deaths' => $deaths[$playerId] ?? 0,
                    'headshots' => $headshots[$playerId] ?? 0,
                    'grenade_kills' => $grenades[$playerId] ?? 0,
                ];
            }
        }
    }
@endphp

<div id="live-status-widget" class="space-y-4" data-server="{{ $server?->slug }}">
    @if(!$server)
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-6 text-center text-sm text-slate-500">
            No hay servidores configurados todavía.
        </div>
    @else
        @if(!$status)
            <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">
                No se pudo conectar al servidor en este momento (RCON no respondió).
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Servidor</div>
                <div class="mt-1 text-sm font-semibold">{!! \App\Support\Cod2Colors::toHtml($server->name) !!}</div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Mapa actual</div>
                <div class="mt-1 text-sm font-semibold">{{ $mapLabel }}</div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Jugadores</div>
                <div class="mt-1 text-sm font-semibold"><span class="text-cyan-400">{{ count($players) }}</span> / {{ $maxClients }}</div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Modo de juego</div>
                <div class="mt-1 text-sm font-semibold">{{ \App\Support\MapCatalog::gametypeLabel($latestMatch?->gametype) }}</div>
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-4">
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden flex flex-col">
                <div class="flex-1 min-h-48 flex items-end p-4 bg-cover bg-center @if(!$mapImageUrl) bg-gradient-to-br {{ $gradient }} @endif"
                    @if($mapImageUrl) style="background-image: url('{{ $mapImageUrl }}')" @endif>
                    <span class="text-white font-semibold text-lg drop-shadow" style="text-shadow: 0 1px 4px rgba(0,0,0,.8)">{{ $mapLabel }}</span>
                </div>
                <div class="px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Mapa actual</div>
                    <div class="mt-0.5 text-sm font-medium">{{ $mapLabel }}</div>
                </div>
            </div>

            <div class="md:col-span-2 rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wide text-slate-400">Jugadores conectados</span>
                    <span class="text-xs text-slate-500">{{ count($players) }} conectados</span>
                </div>
                <div class="max-h-60 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800 sticky top-0 bg-panel">
                                <th class="px-4 py-2 font-medium">Nombre</th>
                                <th class="px-4 py-2 font-medium text-right">Puntaje</th>
                                <th class="px-4 py-2 font-medium text-right">Muertes</th>
                                <th class="px-4 py-2 font-medium text-right">Headshots</th>
                                <th class="px-4 py-2 font-medium text-right">Granadas</th>
                                <th class="px-4 py-2 font-medium text-right">Ping</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($players as $p)
                                @php
                                    $country = \App\Services\GeoIp::countryFor($p['ip'] ?? null);
                                    $stat = $statsByGuid[$p['guid']] ?? null;
                                @endphp
                                <tr class="border-b border-slate-800/60 last:border-0">
                                    <td class="px-4 py-2 font-medium">
                                        @if($country)
                                            <span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>
                                        @endif
                                        @if($p['guid'] !== 0)
                                            <a href="{{ route('players.show', $p['guid']) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p['name'] ?: '(sin nombre)') !!}</a>
                                        @else
                                            <span class="text-slate-500">{!! \App\Support\Cod2Colors::toHtml($p['name'] ?: 'bot') !!}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $p['score'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $stat->deaths ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $stat->headshots ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $stat->grenade_kills ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums
                                        @if(is_numeric($p['ping']) && $p['ping'] >= 150) text-red-400
                                        @elseif(is_numeric($p['ping']) && $p['ping'] >= 80) text-amber-400
                                        @elseif(is_numeric($p['ping'])) text-emerald-400
                                        @else text-slate-500 @endif">
                                        {{ is_numeric($p['ping']) ? $p['ping'].'ms' : $p['ping'] }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Servidor vacío ahora mismo.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Conectar por consola</div>
                <div class="mt-0.5 font-mono text-sm text-cyan-300 truncate">/connect {{ $connectString }}</div>
            </div>
            <button type="button" onclick="cod2CopyConnect(this, {{ json_encode('/connect '.$connectString) }})"
                class="shrink-0 text-xs px-3 py-1.5 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400 transition-all duration-200 ease-out">
                <span class="inline-flex items-center gap-1">Copiar IP</span>
            </button>
        </div>
    @endif
</div>

@once
<style>
    @keyframes cod2-pop {
        0% { transform: scale(0); opacity: 0; }
        60% { transform: scale(1.3); opacity: 1; }
        100% { transform: scale(1); opacity: 1; }
    }
</style>
<script>
window.cod2CopyConnect = function (btn, text) {
    var originalHtml = btn.innerHTML;
    var successClasses = ['border-emerald-500', 'text-emerald-400', 'scale-105'];
    var errorClasses = ['border-red-500', 'text-red-400'];

    // A checkmark that pops in with a scale bounce reads as "done" much faster than
    // a plain text swap did — the button also briefly scales itself up (scale-105)
    // so the state change is visible even out of the corner of your eye.
    var flash = function (ok) {
        btn.innerHTML = ok
            ? '<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 scale-0 animate-[cod2-pop_0.3s_ease-out_forwards]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.79-6.796a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>Copiado</span>'
            : '<span class="inline-flex items-center gap-1">Error</span>';
        btn.classList.add.apply(btn.classList, ok ? successClasses : errorClasses);
        btn.classList.remove('border-slate-700');

        setTimeout(function () {
            btn.innerHTML = originalHtml;
            btn.classList.remove.apply(btn.classList, successClasses.concat(errorClasses));
            btn.classList.add('border-slate-700');
        }, 1500);
    };

    var fallbackCopy = function () {
        var el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus();
        el.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(el);
        flash(ok);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            flash(true);
        }).catch(fallbackCopy);
    } else {
        fallbackCopy();
    }
};

(function () {
    function refresh() {
        var el = document.getElementById('live-status-widget');
        if (!el) return;
        var server = el.dataset.server || '';
        fetch('{{ route('dashboard.live-status') }}?server=' + encodeURIComponent(server))
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                var fresh = wrapper.querySelector('#live-status-widget');
                if (fresh) el.replaceWith(fresh);
            })
            .catch(function () {});
    }
    setInterval(refresh, 12000);
})();
</script>
@endonce
