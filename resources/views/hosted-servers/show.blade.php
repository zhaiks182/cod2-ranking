@extends('layouts.app')

@section('title', $server->hostname)

@section('content')
@php
    $statusLabels = [
        'starting' => ['Iniciando…', 'text-amber-400 border-amber-900 bg-amber-950/30'],
        'running' => ['En línea', 'text-emerald-400 border-emerald-900 bg-emerald-950/30'],
        'failed' => ['No se pudo iniciar', 'text-red-400 border-red-900 bg-red-950/30'],
        'stopped' => ['Detenido', 'text-slate-400 border-slate-700 bg-slate-800/30'],
        'expired' => ['Expirado', 'text-slate-400 border-slate-700 bg-slate-800/30'],
    ];
    [$statusLabel, $statusClasses] = $statusLabels[$server->status] ?? ['Desconocido', 'text-slate-400 border-slate-700'];
    $connect = $server->connectString();
    // Mismo estilo de hero que crear-servidor (fondo con mapa, titulo grande) -- a
    // diferencia de ese, que rota varios mapas, aca es UNA imagen fija: la del mapa
    // real de este server, mas relevante que un carrusel generico. Si no hay imagen
    // subida para ese mapa, cae al encabezado simple de siempre (sin dejar un hueco).
    $heroImage = \App\Support\MapImage::url($server->map);
@endphp
<div class="space-y-6">
    @if ($heroImage)
        <section class="relative rounded-2xl overflow-hidden border border-slate-800">
            <div class="absolute inset-0 bg-cover bg-center" style="background-image:url('{{ $heroImage }}')"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-panel2 via-panel2/85 to-panel2/50"></div>
            <div class="relative px-4 sm:px-6 py-10 sm:py-14 text-center">
                <span class="inline-block text-xs px-2.5 py-1 rounded-lg border {{ $statusClasses }} mb-3">{{ $statusLabel }}</span>
                <h1 class="font-display text-2xl sm:text-3xl md:text-4xl font-bold text-white leading-tight break-words">{{ $server->hostname }}</h1>
                <p class="mt-3 text-[10px] sm:text-xs md:text-sm text-slate-300 uppercase tracking-[0.15em]">{{ \App\Support\MapCatalog::mapLabel($server->map) }} · {{ $server->slots }} jugadores</p>
            </div>
        </section>
    @else
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl md:text-3xl font-bold text-white break-words">{{ $server->hostname }}</h1>
                <p class="text-sm text-slate-400 mt-1">{{ \App\Support\MapCatalog::mapLabel($server->map) }} · {{ $server->slots }} jugadores</p>
            </div>
            <span class="shrink-0 text-xs px-2.5 py-1 rounded-lg border {{ $statusClasses }}">{{ $statusLabel }}</span>
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-xl border border-emerald-900 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if ($server->status === 'running')
        <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-3">
            <div>
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Conectar por consola</span>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-cyan-300 text-xs overflow-x-auto whitespace-nowrap">{{ $connect }}</code>
                    <button type="button" onclick="cod2CopyConnect(this, {{ json_encode($connect) }})"
                        class="shrink-0 text-xs px-3 py-2 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400 transition-colors">Copiar</button>
                </div>
            </div>

            <div>
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Administrar por RCON</span>
                <p class="mt-1 text-xs text-slate-500">Solo vos la ves — pegalo en la consola del juego (~) para administrar tu server.</p>
                @php $rconLogin = '/rcon login '.$server->rcon_password; @endphp
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-cyan-300 text-xs overflow-x-auto whitespace-nowrap">{{ $rconLogin }}</code>
                    <button type="button" onclick="cod2CopyConnect(this, {{ json_encode($rconLogin) }})"
                        class="shrink-0 text-xs px-3 py-2 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400 transition-colors">Copiar</button>
                </div>
            </div>

            <div>
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Se apaga en</span>
                <p id="hosted-server-countdown" class="mt-1 text-lg font-semibold text-white tabular-nums" data-expires-at="{{ $server->expires_at->toIso8601String() }}">—</p>
                <p class="text-[11px] text-slate-500 mt-0.5">También se apaga solo si queda vacío por más de {{ config('hosted_servers.idle_minutes') }} minutos.</p>
            </div>
        </div>

        <div class="rounded-xl border border-amber-900 bg-amber-950/30 px-4 py-3 text-xs text-amber-300">
            Modo Search &amp; Destroy — necesita que 2 jugadores confirmen "listo" antes de que arranque la primera ronda.
        </div>

        <form method="POST" action="{{ route('hosted-servers.stop', [$server, $server->management_token]) }}"
            onsubmit="return confirm('¿Detener este servidor ahora? No se puede deshacer.')">
            @csrf
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg border border-red-900 text-red-400 hover:bg-red-950/40 text-sm font-medium transition-colors">Detener servidor</button>
        </form>
    @elseif ($server->status === 'starting')
        <div class="rounded-xl border border-slate-800 bg-panel p-6 text-center text-sm text-slate-400">
            Iniciando el servidor… esto puede tardar unos segundos. Actualizá la página en un momento.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel p-6 text-center text-sm text-slate-400">
            Este servidor ya no está activo.
            <a href="{{ route('hosted-servers.create') }}" class="text-gsaccent hover:underline">Crear uno nuevo</a>.
        </div>
    @endif
</div>

<script>
    (function () {
        var el = document.getElementById('hosted-server-countdown');
        if (!el) return;

        var expiresAt = new Date(el.dataset.expiresAt).getTime();

        function tick() {
            var diff = expiresAt - Date.now();
            if (diff <= 0) {
                el.textContent = 'Expirado';
                return;
            }
            var h = Math.floor(diff / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            var s = Math.floor((diff % 60000) / 1000);
            el.textContent = h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
            setTimeout(tick, 1000);
        }

        tick();
    })();
</script>
@endsection
