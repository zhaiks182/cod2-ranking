@extends('layouts.admin')

@section('title', 'Consola — '.$server->name)

@section('content')
@php $players = $status['players'] ?? []; @endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-lg font-semibold">{{ $server->name }}</h1>
            <p class="text-xs text-slate-500">
                Mapa actual: {{ \App\Support\MapCatalog::mapLabel($status['map'] ?? null) }}
                @isset($serviceStartedAt)
                    @if($serviceStartedAt)
                        · <span class="text-emerald-400" title="El servicio {{ $server->systemd_service }} está activo desde {{ $serviceStartedAt->format('d/m/Y H:i') }}">Servicio activo hace <span id="cod2-service-uptime" data-since="{{ $serviceStartedAt->toIso8601String() }}">{{ \App\Support\ServiceUptime::format($serviceStartedAt) }}</span></span>
                    @elseif($server->systemd_service)
                        · <span class="text-red-400">Servicio detenido</span>
                    @endif
                @endisset
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($server->systemd_service)
                @if($status)
                    <form method="POST" action="{{ route('admin.console.service', $server) }}" onsubmit="return confirm('¿Reiniciar el servicio {{ $server->systemd_service }}? Esto desconecta a todos los jugadores conectados ahora mismo. Esta acción no se puede deshacer.')">
                        @csrf
                        <input type="hidden" name="action" value="restart">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-amber-900 text-amber-400 hover:bg-amber-950/40">Reiniciar servicio</button>
                    </form>
                    <form method="POST" action="{{ route('admin.console.service', $server) }}" onsubmit="return confirm('¿Detener el servicio {{ $server->systemd_service }}? El servidor deja de estar jugable hasta que lo vuelvas a iniciar. Esta acción no se puede deshacer.')">
                        @csrf
                        <input type="hidden" name="action" value="stop">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-red-900 text-red-400 hover:bg-red-950/40">Detener servicio</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.console.service', $server) }}" onsubmit="return confirm('¿Iniciar el servicio {{ $server->systemd_service }}?')">
                        @csrf
                        <input type="hidden" name="action" value="start">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-emerald-900 text-emerald-400 hover:bg-emerald-950/40">Iniciar servicio</button>
                    </form>
                @endif
            @endif
            @if($server->systemd_service)
                <a href="{{ route('admin.console.resources', $server) }}" class="text-xs text-violet-400 hover:underline">Recursos (CPU/RAM) →</a>
            @endif
            <a href="{{ route('admin.servers.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← Servidores</a>
        </div>
    </div>

    {{-- Config del veto de pugs (2026-09-05). Es global, no por servidor, pero vive
    aca porque es donde el admin ya maneja los mapas. --}}
    <details class="rounded-xl border border-slate-800 bg-panel">
        <summary class="px-4 py-3 text-sm font-semibold cursor-pointer text-slate-300 hover:text-cyan-400">
            🎯 Veto de pugs — mapas del pool
        </summary>
        <form method="POST" action="{{ route('admin.console.pug-settings') }}" class="px-4 pb-4 space-y-3">
            @csrf
            @method('PUT')
            @if($errors->any())
                <div class="rounded-lg border border-red-900 bg-red-950/40 px-3 py-2 text-xs text-red-300">{{ $errors->first() }}</div>
            @endif
            @php
                $pool = \App\Support\PugManager::pool();
                $mapsCount = \App\Models\Setting::current()->pug_maps_count ?? 3;
            @endphp
            <p class="text-xs text-slate-500">
                Los mapas marcados son los que entran al veto. La diferencia entre el pool y los mapas a jugar
                tiene que ser <strong class="text-slate-400">par</strong>, para que cada capitán banee la misma cantidad.
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach(\App\Support\MapCatalog::pickerOptions() as $code => $label)
                    <label class="flex items-center gap-2 text-xs text-slate-300 rounded-lg border border-slate-800 px-2 py-1.5 cursor-pointer hover:border-slate-600">
                        <input type="checkbox" name="pug_veto_pool[]" value="{{ $code }}" @checked(in_array($code, $pool, true))>
                        <span class="truncate">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <div class="flex items-end gap-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Mapas a jugar</label>
                    <input type="number" name="pug_maps_count" min="1" max="9" value="{{ $mapsCount }}"
                        class="w-20 bg-panel2 border border-slate-700 rounded-lg px-2 py-1.5 text-sm text-slate-200">
                </div>
                <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-700 text-sm hover:border-cyan-500 hover:text-cyan-400">Guardar</button>
            </div>
        </form>
    </details>

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
                            <button type="button"
                                onclick="cod2Ban({{ $p['slot'] }}, {{ $p['guid'] }}, {{ json_encode($p['name']) }}, {{ json_encode(\App\Support\Cod2Colors::stripColors($p['name'])) }})"
                                class="text-xs px-2 py-1 rounded border border-red-900 text-red-400 hover:bg-red-950/40">Ban</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Servidor vacío.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @include('partials.team-balance', ['teamBalance' => $teamBalance, 'discordNotifyAction' => route('admin.console.notify-teams', $server), 'rebalanceAction' => route('admin.console.rebalance-teams', $server)])

    <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-3">
        <h2 class="text-xs uppercase tracking-wide text-slate-400">Mensaje a todos</h2>
        <form method="POST" action="{{ route('admin.console.message', $server) }}" class="flex gap-2">
            @csrf
            <input type="hidden" name="mode" value="all">
            <input type="text" name="text" required maxlength="200" placeholder="Escribe un mensaje…" class="flex-1 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm">
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Enviar</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-4">
        <h2 class="text-xs uppercase tracking-wide text-slate-400">Cambiar mapa</h2>
        @php
            // Listado completo de mapas custom instalados en el server (confirmado
            // 2026-08-18 contra el directorio real de mapas del dueño, los .d3dbsp/
            // .gsc de cada variante). El mapeo codigo->sufijo vive centralizado en
            // MapCatalog::variantSuffix() (antes duplicado aca) para no tener dos
            // listas que mantener sincronizadas.
            $mapVariants = collect(\App\Support\MapCatalog::variantCodes())->mapWithKeys(
                fn ($code) => [$code => \App\Support\MapCatalog::variantSuffix($code)]
            )->all();
            $mapOptions = [];
            foreach (\App\Support\MapCatalog::all() as $code => $label) {
                $mapOptions[$code] = ['label' => $label, 'suffix' => null];
            }
            foreach ($mapVariants as $code => $suffix) {
                $mapOptions[$code] = ['label' => \App\Support\MapCatalog::mapLabel($code), 'suffix' => $suffix];
            }

            // Orden a pedido del dueño (2026-08-18): TODOS los mapas "_fix" van
            // primero, sin excepcion — antes se colaban variantes no-fix
            // (dawnville_sun, railyard_mjr/railyard, carentan_bal) mezcladas entre
            // medio porque estaban en la lista de prioridad general. Ahora son dos
            // grupos separados: fix (con toujane/burgundy/dawnville/carentan al
            // frente de ESE grupo) y todo el resto despues (con railyard/stalingrad
            // al frente de ese segundo grupo, ya que no tiene variante fix).
            $fixCodes = array_values(array_filter(array_keys($mapOptions), fn ($c) => str_ends_with($c, '_fix')));
            $fixPriority = ['mp_toujane_fix', 'mp_burgundy_fix', 'mp_dawnville_fix', 'mp_carentan_fix'];
            $fixOrdered = array_unique(array_merge($fixPriority, $fixCodes));

            $restCodes = array_values(array_diff(array_keys($mapOptions), $fixOrdered));
            $restPriority = ['mp_railyard_mjr', 'mp_railyard'];
            $restOrdered = array_unique(array_merge($restPriority, $restCodes));

            $orderedCodes = array_merge($fixOrdered, $restOrdered);
            $mapOptions = collect($orderedCodes)->mapWithKeys(fn ($c) => [$c => $mapOptions[$c]])->all();
        @endphp
        <form method="POST" action="{{ route('admin.console.map', $server) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="map" id="map-select-value">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                @foreach($mapOptions as $code => $opt)
                    @php $mapImageUrl = \App\Support\MapImage::url($code); @endphp
                    <button type="button" data-map-option data-code="{{ $code }}" data-label="{{ $opt['label'] }}{{ $opt['suffix'] ? ' '.$opt['suffix'] : '' }}"
                        class="flex items-center gap-2 px-2 py-2 rounded-lg border border-slate-700 text-left hover:border-cyan-500 hover:text-cyan-400 transition-colors">
                        @if($mapImageUrl)
                            <img src="{{ $mapImageUrl }}" alt="" class="h-9 w-9 rounded object-cover shrink-0">
                        @else
                            <span class="h-9 w-9 rounded bg-panel2 shrink-0"></span>
                        @endif
                        <span class="min-w-0">
                            <span class="block text-xs font-medium text-slate-200 truncate">{{ $opt['label'] }}@if($opt['suffix']) <span class="text-cyan-400">{{ $opt['suffix'] }}</span>@endif</span>
                            <span class="block text-[10px] text-slate-500 truncate">{{ $code }}</span>
                        </span>
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
<form id="cod2-ban-form" method="POST" action="{{ route('admin.console.ban', $server) }}" class="hidden">
    @csrf
    <input type="hidden" name="slot" id="cod2-ban-slot">
    <input type="hidden" name="guid" id="cod2-ban-guid">
    <input type="hidden" name="name" id="cod2-ban-name">
    <input type="hidden" name="reason" id="cod2-ban-reason">
</form>
<script>
    function cod2Message(slot, name) {
        var text = prompt('Mensaje privado para ' + name + ':');
        if (!text) return;
        document.getElementById('cod2-message-slot').value = slot;
        document.getElementById('cod2-message-text').value = text;
        document.getElementById('cod2-message-form').submit();
    }

    function cod2Ban(slot, guid, name, plainName) {
        if (!confirm('¿Banear a ' + plainName + '? Es permanente hasta que lo desbaneés a mano en /adm_cod2/bans.')) return;
        var reason = prompt('Motivo del ban (opcional):') || '';
        document.getElementById('cod2-ban-slot').value = slot;
        document.getElementById('cod2-ban-guid').value = guid;
        document.getElementById('cod2-ban-name').value = name;
        document.getElementById('cod2-ban-reason').value = reason;
        document.getElementById('cod2-ban-form').submit();
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

    // Uptime del servicio: se renderiza ya calculado desde el server (asi es
    // correcto aunque el JS no corra) y desde ahi lo lleva el navegador solo,
    // cada minuto, contra la fecha de arranque que vino en data-since. No le
    // pega al servidor: el unico dato que necesita ya esta en el HTML. El
    // formato tiene que coincidir con ServiceUptime::format().
    (function () {
        var el = document.getElementById('cod2-service-uptime');
        if (!el) return;

        var since = new Date(el.dataset.since).getTime();

        function tick() {
            var minutes = Math.max(0, Math.floor((Date.now() - since) / 60000));
            var days = Math.floor(minutes / 1440);
            var hours = Math.floor((minutes % 1440) / 60);
            var mins = minutes % 60;

            el.textContent = days > 0 ? (days + 'd ' + hours + 'h ' + mins + 'm')
                : hours > 0 ? (hours + 'h ' + mins + 'm')
                : (mins + 'm');
        }

        setInterval(tick, 60000);
    })();

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
