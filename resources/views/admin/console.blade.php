@extends('layouts.admin')

@section('title', 'Consola — '.$server->name)

@section('content')
@php $players = $status['players'] ?? []; @endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-lg font-semibold">{{ $server->name }}</h1>
            <p class="text-xs text-slate-500">Mapa actual: {{ \App\Support\MapCatalog::mapLabel($status['map'] ?? null) }}</p>
        </div>
        <a href="{{ route('admin.servers.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← Servidores</a>
    </div>

    @if(!$status && session('mapChanging'))
        <div class="rounded-xl border border-amber-900 bg-amber-950/40 px-4 py-3 text-sm text-amber-300">El servidor está cargando el mapa nuevo — puede tardar varios segundos en volver a responder por RCON. Actualizá la página en un momento.</div>
    @elseif(!$status)
        <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">No se pudo conectar por RCON a este servidor.</div>
    @endif

    @if(session('lastCommand'))
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Resultado de: <span class="font-mono text-slate-300">{{ session('lastCommand') }}</span></div>
            <pre class="mt-2 text-xs text-slate-300 whitespace-pre-wrap">{{ session('lastResult') ?: '(sin salida)' }}</pre>
        </div>
    @endif

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 text-xs uppercase tracking-wide text-slate-400">Jugadores conectados ({{ count($players) }})</div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Slot</th>
                    <th class="px-4 py-2 font-medium">Nombre</th>
                    <th class="px-4 py-2 font-medium">IP</th>
                    <th class="px-4 py-2 font-medium text-right">Puntaje</th>
                    <th class="px-4 py-2 font-medium text-right">Ping</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($players as $p)
                    @php $country = \App\Services\GeoIp::countryFor($p['ip'] ?? null); @endphp
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 tabular-nums text-slate-500">{{ $p['slot'] }}</td>
                        <td class="px-4 py-2 font-medium">
                            @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                            {!! \App\Support\Cod2Colors::toHtml($p['name'] ?: '(sin nombre)') !!}
                        </td>
                        <td class="px-4 py-2 text-slate-400 font-mono text-xs">{{ $p['ip'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $p['score'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $p['ping'] }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap space-x-2">
                            <button type="button"
                                onclick="cod2Message({{ $p['slot'] }}, {{ json_encode($p['name']) }})"
                                class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Mensaje</button>
                            <form method="POST" action="{{ route('admin.console.kick', $server) }}" class="inline" onsubmit="return confirm('¿Expulsar a {{ addslashes(\App\Support\Cod2Colors::stripColors($p['name'])) }}?')">
                                @csrf
                                <input type="hidden" name="slot" value="{{ $p['slot'] }}">
                                <button type="submit" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-red-500 hover:text-red-400">Kick</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Servidor vacío.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-3">
        <h2 class="text-xs uppercase tracking-wide text-slate-400">Mensaje a todos</h2>
        <form method="POST" action="{{ route('admin.console.message', $server) }}" class="flex gap-2">
            @csrf
            <input type="hidden" name="mode" value="all">
            <input type="text" name="text" required maxlength="200" placeholder="Escribe un mensaje…" class="flex-1 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm">
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Enviar</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-3">
        <h2 class="text-xs uppercase tracking-wide text-slate-400">Cambiar mapa</h2>
        @php
            // Listado completo de mapas custom instalados en el server (confirmado
            // 2026-08-18 contra el directorio real de mapas del dueño, los .d3dbsp/
            // .gsc de cada variante) — mp_chelm_fix, mp_crossroads y mp_vallente_fix
            // no tienen entrada en MapCatalog::MAPS (no son mapas stock de CoD2,
            // asi que mapLabel() cae al fallback generico basado en el codigo) y
            // wawa_3daim no lleva el prefijo "mp_" (es el mapa de aim-trainer para
            // Deathmatch, ver "Cuando se crea una partida" en CLAUDE.md).
            $mapVariants = [
                'mp_breakout_tls' => 'TLS',
                'mp_burgundy_fix' => 'FIX',
                'mp_carentan_bal' => 'BAL', 'mp_carentan_fix' => 'FIX',
                'mp_chelm_fix' => 'FIX',
                'mp_crossroads' => null,
                'mp_dawnville_fix' => 'FIX', 'mp_dawnville_sun' => 'SUN',
                'mp_leningrad_mjr' => 'MJR', 'mp_leningrad_tls' => 'TLS',
                'mp_matmata_fix' => 'FIX',
                'mp_railyard_mjr' => 'MJR',
                'mp_toujane_fix' => 'FIX',
                'mp_trainstation_bhg' => 'BHG', 'mp_trainstation_fix' => 'FIX',
                'mp_vallente_fix' => 'FIX',
                'wawa_3daim' => null,
            ];
            $mapOptions = [];
            foreach (\App\Support\MapCatalog::all() as $code => $label) {
                $mapOptions[$code] = ['label' => $label, 'suffix' => null];
            }
            foreach ($mapVariants as $code => $suffix) {
                $mapOptions[$code] = ['label' => \App\Support\MapCatalog::mapLabel($code), 'suffix' => $suffix];
            }
        @endphp
        <form method="POST" action="{{ route('admin.console.map', $server) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="map" id="map-select-value">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                @foreach($mapOptions as $code => $opt)
                    <button type="button" data-map-option data-code="{{ $code }}" data-label="{{ $opt['label'] }}{{ $opt['suffix'] ? ' '.$opt['suffix'] : '' }}"
                        class="px-3 py-2 rounded-lg border border-slate-700 text-left hover:border-cyan-500 hover:text-cyan-400 transition-colors">
                        <span class="block text-xs font-medium text-slate-200">{{ $opt['label'] }}@if($opt['suffix']) <span class="text-cyan-400">{{ $opt['suffix'] }}</span>@endif</span>
                        <span class="block text-[10px] text-slate-500">{{ $code }}</span>
                    </button>
                @endforeach
            </div>
            <div class="flex items-center gap-2 pt-1">
                <span class="text-xs text-slate-500">Seleccionado: <span id="map-select-label" class="text-cyan-400 font-medium">ninguno</span></span>
                <button type="submit" id="map-select-submit" disabled class="ml-auto px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium">Cambiar</button>
            </div>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-3">
        <h2 class="text-xs uppercase tracking-wide text-slate-400">Comando RCON libre</h2>
        <form method="POST" action="{{ route('admin.console.command', $server) }}" class="flex gap-2">
            @csrf
            <input type="text" name="cmd" required maxlength="500" placeholder="ej: g_gametype sd" class="flex-1 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono">
            <button type="submit" class="px-3 py-2 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400 text-sm font-medium">Ejecutar</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
            <span class="text-xs uppercase tracking-wide text-slate-400">Consola del juego (en vivo)</span>
            <label class="flex items-center gap-1.5 text-[11px] text-slate-500">
                <input type="checkbox" id="cod2-log-pause" class="accent-cyan-500"> Pausar
            </label>
        </div>
        <pre id="cod2-log-tail" class="h-72 overflow-y-auto px-4 py-3 text-[11px] leading-relaxed text-slate-400 font-mono whitespace-pre-wrap"></pre>
    </div>
</div>

<form id="cod2-message-form" method="POST" action="{{ route('admin.console.message', $server) }}" class="hidden">
    @csrf
    <input type="hidden" name="mode" value="private">
    <input type="hidden" name="slot" id="cod2-message-slot">
    <input type="hidden" name="text" id="cod2-message-text">
</form>
<script>
    function cod2Message(slot, name) {
        var text = prompt('Mensaje privado para ' + name + ':');
        if (!text) return;
        document.getElementById('cod2-message-slot').value = slot;
        document.getElementById('cod2-message-text').value = text;
        document.getElementById('cod2-message-form').submit();
    }

    document.querySelectorAll('[data-map-option]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-map-option]').forEach(function (b) {
                b.classList.remove('border-cyan-500', 'text-cyan-400', 'bg-cyan-950/30');
            });
            btn.classList.add('border-cyan-500', 'text-cyan-400', 'bg-cyan-950/30');
            document.getElementById('map-select-value').value = btn.dataset.code;
            document.getElementById('map-select-label').textContent = btn.dataset.label;
            document.getElementById('map-select-submit').disabled = false;
        });
    });

    (function () {
        var box = document.getElementById('cod2-log-tail');
        var pause = document.getElementById('cod2-log-pause');
        var url = @json(route('admin.console.log-tail', $server));
        var offset = null;
        var maxLines = 500;

        function poll() {
            if (pause.checked) return;
            var q = offset === null ? url : (url + '?offset=' + offset);
            fetch(q).then(function (r) { return r.json(); }).then(function (data) {
                offset = data.offset;
                if (data.lines.length) {
                    var atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 20;
                    var text = box.textContent ? box.textContent + '\n' + data.lines.join('\n') : data.lines.join('\n');
                    var allLines = text.split('\n');
                    if (allLines.length > maxLines) {
                        allLines = allLines.slice(allLines.length - maxLines);
                    }
                    box.textContent = allLines.join('\n');
                    if (atBottom) box.scrollTop = box.scrollHeight;
                }
            }).catch(function () {});
        }

        poll();
        setInterval(poll, 3000);
    })();
</script>
@endsection
