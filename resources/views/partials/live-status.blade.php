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

<div id="live-status-widget" class="space-y-6" data-server="{{ $server?->slug }}">
    @if(!$server)
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-6 text-center text-sm text-slate-500">
            No hay servidores configurados todavía.
        </div>
    @else
        @if(!$status)
            <div class="rounded-xl border border-gsaccent/40 bg-gsaccent/10 px-4 py-3 text-sm text-rose-200 font-semibold">
                No se pudo conectar al servidor en este momento (RCON no respondió).
            </div>
        @endif

        <div class="flex flex-col sm:flex-row sm:items-end gap-5 rounded-xl border border-slate-800 bg-panel px-4 py-4">
            <div class="flex flex-wrap items-end gap-x-8 gap-y-3">
                <div>
                    <div class="flex items-baseline gap-1 font-semibold text-3xl sm:text-4xl tabular-nums" data-stat="players-count">
                        <span class="text-cyan-400">{{ count($players) }}</span>
                        <span class="text-slate-500 text-xl sm:text-2xl">/ {{ $maxClients }}</span>
                    </div>
                    <div class="mt-1 text-[11px] uppercase tracking-[0.2em] text-slate-400">Jugadores conectados</div>
                </div>

                <div class="min-w-0 sm:ml-24">
                    <div class="text-2xl font-medium text-white truncate" data-stat="map">{{ $mapLabel }}</div>
                    <div class="mt-1 text-[11px] uppercase tracking-[0.2em] text-slate-400 truncate">
                        <span data-stat="gametype">{{ \App\Support\MapCatalog::gametypeLabel($latestMatch?->gametype) }}</span>
                        <span class="text-slate-600 mx-1.5">·</span>
                        {!! \App\Support\Cod2Colors::toHtml($server->name) !!}
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:ml-auto pt-4 sm:pt-0 border-t border-slate-800 sm:border-t-0">
                <div class="min-w-0 flex-1 sm:flex-initial sm:text-right">
                    <div class="text-[11px] uppercase tracking-[0.2em] text-slate-400">Conectar por consola</div>
                    <div class="mt-1 font-mono text-sm text-cyan-300 truncate">/connect {{ $connectString }}</div>
                </div>
                <button type="button" onclick="cod2CopyConnect(this, {{ json_encode('/connect '.$connectString) }})"
                    class="shrink-0 text-xs px-3 py-1.5 rounded-lg border border-slate-600 text-slate-200 hover:border-gsaccent hover:text-gsaccent transition-all duration-200 ease-out">
                    <span class="inline-flex items-center gap-1">Copiar IP</span>
                </button>
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-4">
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden flex flex-col">
                <div class="flex-1 min-h-64 flex items-end p-5 bg-cover bg-center @if(!$mapImageUrl) bg-gradient-to-br {{ $gradient }} @endif"
                    @if($mapImageUrl) style="background-image: url('{{ $mapImageUrl }}')" @endif>
                    <span class="text-white font-medium text-xl drop-shadow" style="text-shadow: 0 1px 6px rgba(0,0,0,.85)">{{ $mapLabel }}</span>
                </div>
            </div>

            <div class="md:col-span-2 rounded-xl border border-slate-800 bg-panel flex flex-col overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <span class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Jugadores conectados</span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-950/50 border border-emerald-900 text-emerald-400 text-[11px]" data-stat="players-count-label">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        {{ count($players) }} conectados
                    </span>
                </div>
                <div class="max-h-60 overflow-y-auto overflow-x-auto px-4">
                    <table class="w-full text-sm min-w-[480px]">
                        <thead>
                            <tr class="text-left text-[10px] uppercase tracking-[0.15em] text-slate-600 sticky top-0 bg-panel">
                                <th class="py-2 font-medium">Nombre</th>
                                <th class="py-2 font-medium text-right">Puntaje</th>
                                <th class="py-2 font-medium text-right">Muertes</th>
                                <th class="py-2 font-medium text-right">Headshots</th>
                                <th class="py-2 font-medium text-right">Granadas</th>
                                <th class="py-2 font-medium text-right">Ping</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            @forelse($players as $p)
                                @php
                                    $country = \App\Services\GeoIp::countryFor($p['ip'] ?? null);
                                    $stat = $statsByGuid[$p['guid']] ?? null;
                                @endphp
                                <tr class="hover:bg-panel/60 transition-colors duration-150">
                                    <td class="py-2.5 font-medium">
                                        @if($country)
                                            <span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>
                                        @endif
                                        @if($p['guid'] !== 0)
                                            <a href="{{ route('players.show', $p['guid']) }}" class="hover:text-gsaccent">{!! \App\Support\Cod2Colors::toHtml($p['name'] ?: '(sin nombre)') !!}</a>
                                        @else
                                            <span class="text-slate-500">{!! \App\Support\Cod2Colors::toHtml($p['name'] ?: 'bot') !!}</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums">{{ $p['score'] }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-slate-400">{{ $stat->deaths ?? '—' }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-slate-400">{{ $stat->headshots ?? '—' }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-slate-400">{{ $stat->grenade_kills ?? '—' }}</td>
                                    <td class="py-2.5 text-right tabular-nums
                                        @if(is_numeric($p['ping']) && $p['ping'] >= 150) text-red-400
                                        @elseif(is_numeric($p['ping']) && $p['ping'] >= 80) text-amber-400
                                        @elseif(is_numeric($p['ping'])) text-emerald-400
                                        @else text-slate-500 @endif">
                                        {{ is_numeric($p['ping']) ? $p['ping'].'ms' : $p['ping'] }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-8 text-center text-slate-600">Servidor vacío ahora mismo.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>

@once
<style>
    @keyframes cod2-stat-flash {
        0% { background-color: transparent; }
        30% { background-color: rgba(34, 211, 238, 0.14); }
        100% { background-color: transparent; }
    }

    @keyframes cod2-stat-pop {
        0% { transform: scale(1); }
        35% { transform: scale(1.12); color: #22d3ee; }
        100% { transform: scale(1); }
    }

    [data-stat].cod2-changed {
        animation: cod2-stat-flash 900ms ease-out;
        border-radius: 6px;
    }
    [data-stat="players-count"].cod2-changed {
        display: inline-flex;
        animation: cod2-stat-pop 500ms cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @media (prefers-reduced-motion: reduce) {
        [data-stat].cod2-changed,
        [data-stat="players-count"].cod2-changed { animation: none; }
    }
</style>
<script>
// cod2CopyConnect() ahora vive en layouts/app.blade.php (compartido con
// cualquier pagina, no solo esta) -- ver comentario ahi.
(function () {
    function statText(root, key) {
        var node = root.querySelector('[data-stat="' + key + '"]');
        return node ? node.textContent.trim() : null;
    }

    function markChangedStats(oldEl, freshEl) {
        var keys = ['map', 'players-count', 'gametype', 'players-count-label'];
        keys.forEach(function (key) {
            var before = statText(oldEl, key);
            var after = statText(freshEl, key);
            if (before !== null && after !== null && before !== after) {
                var node = freshEl.querySelector('[data-stat="' + key + '"]');
                if (node) node.classList.add('cod2-changed');
            }
        });
    }

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
                if (!fresh) return;

                // Only mark and swap in the DOM — no fade on the whole widget. A
                // silent swap reads as "still the same page" every 12s; dimming the
                // whole block in and out read as a page reload instead, which is the
                // opposite of what a "live" widget should feel like. The per-field
                // flash on markChangedStats() is the only visible cue, and only fires
                // when something actually changed.
                markChangedStats(el, fresh);
                el.replaceWith(fresh);
            })
            .catch(function () {});
    }
    setInterval(refresh, 12000);
})();
</script>
@endonce
