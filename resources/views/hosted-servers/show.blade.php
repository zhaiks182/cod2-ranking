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
@endphp
<div class="max-w-xl mx-auto space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl md:text-3xl font-bold text-white break-words">{{ $server->hostname }}</h1>
            <p class="text-sm text-slate-400 mt-1">{{ \App\Support\MapCatalog::mapLabel($server->map) }} · {{ $server->slots }} jugadores</p>
        </div>
        <span class="shrink-0 text-xs px-2.5 py-1 rounded-lg border {{ $statusClasses }}">{{ $statusLabel }}</span>
    </div>

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
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Contraseña RCON</span>
                <p class="mt-1 text-xs text-slate-500">Solo vos la ves — te sirve para administrar tu server (<code class="text-cyan-300">rcon login {{ $server->rcon_password }}</code> desde la consola del juego).</p>
                <code class="mt-1 inline-block px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-cyan-300 text-xs">{{ $server->rcon_password }}</code>
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

    <p class="text-[11px] text-slate-500">Guardá esta página — es la única forma de administrar o detener este servidor, no hay ningún login asociado.</p>
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
