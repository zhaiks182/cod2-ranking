@extends('layouts.admin')

@section('title', 'Borrar jugadores')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Borrar jugadores</h1>
        <p class="text-xs text-slate-500 mt-1">
            Borra el perfil completo: alias y stats por mapa/servidor/arma desaparecen.
            Sus kills, mensajes de chat, bans y demos <strong>no</strong> se borran — quedan en el
            historial con el guid/nombre tal cual estaban, igual que ya pasa con los kills de un
            bot, solo que ya no suman a ningún ranking. <strong>No se puede deshacer.</strong>
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if($zeroActivityCount > 0)
        <div class="rounded-xl border border-red-900/60 bg-red-950/20 px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="text-sm text-red-200">
                <strong>{{ $zeroActivityCount }}</strong> jugador(es) con 0 kills y 0 deaths (nunca jugaron una partida real —
                ver "filas fantasma" en el CLAUDE.md). No hay nada real que perder al borrarlos.
            </div>
            <form method="POST" action="{{ route('admin.players.delete.bulk-zero-activity') }}" id="bulk-delete-form">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg bg-red-900/60 border border-red-800 px-4 py-2 text-sm font-medium text-red-200 hover:bg-red-900">
                    Borrar los {{ $zeroActivityCount }} sin actividad
                </button>
            </form>
        </div>
    @endif

    @if($players->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            No hay jugadores registrados.
        </div>
    @else
        <input type="text" id="player-delete-search" placeholder="Buscar por nombre, alias o guid…"
            class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 text-sm focus:outline-none focus:border-gsaccent">

        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-3 py-2 font-medium w-10">#</th>
                        <th class="px-3 py-2 font-medium">Jugador</th>
                        <th class="px-3 py-2 font-medium">guid</th>
                        <th class="px-3 py-2 font-medium text-right">Acción</th>
                    </tr>
                </thead>
                <tbody id="player-delete-rows">
                    @foreach($players as $player)
                        @php
                            $aliasNames = $player->aliases->pluck('name_plain')->unique();
                        @endphp
                        <tr class="player-delete-row border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30"
                            data-search="{{ mb_strtolower($player->last_name_plain.' '.$player->guid.' '.$aliasNames->implode(' ')) }}">
                            <td class="px-3 py-2 text-slate-500 tabular-nums">{{ $loop->iteration }}</td>
                            <td class="px-3 py-2 font-medium">
                                <a href="{{ route('players.show', $player->guid) }}" target="_blank" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</a>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-400">{{ $player->guid }}</td>
                            <td class="px-3 py-2 text-right">
                                <form method="POST" action="{{ route('admin.players.delete.destroy', $player) }}"
                                    class="player-delete-form"
                                    data-name="{{ $player->last_name_plain }}"
                                    data-guid="{{ $player->guid }}"
                                    data-kills="{{ $player->kills_total }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:underline">Borrar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <p id="player-delete-empty" class="hidden text-center text-sm text-slate-500 py-6">
            Ningún jugador coincide con esa búsqueda.
        </p>
    @endif
</div>

<script>
    document.querySelectorAll('.player-delete-form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            const { name, guid, kills } = form.dataset;

            const first = confirm(
                '¿Borrar a "' + name + '" (guid ' + guid + ', ' + kills + ' kills)?\n\n' +
                'Se pierden su perfil, alias y stats cacheadas. Sus kills quedan en el historial sin dueño.'
            );
            if (!first) { e.preventDefault(); return; }

            const second = confirm(
                'Última confirmación: esto NO se puede deshacer.\n\n' +
                '¿Borrar definitivamente a "' + name + '"?'
            );
            if (!second) { e.preventDefault(); }
        });
    });

    document.getElementById('bulk-delete-form')?.addEventListener('submit', (e) => {
        const first = confirm(
            '¿Borrar TODOS los jugadores con 0 kills y 0 deaths ({{ $zeroActivityCount }} en total)?\n\n' +
            'Ninguno tiene stats reales que perder, pero es una acción en bloque.'
        );
        if (!first) { e.preventDefault(); return; }

        const second = confirm(
            'Última confirmación: esto NO se puede deshacer.\n\n' +
            '¿Borrar definitivamente los {{ $zeroActivityCount }} perfiles?'
        );
        if (!second) { e.preventDefault(); }
    });

    (function () {
        const input = document.getElementById('player-delete-search');
        if (!input) return;

        const rows = document.querySelectorAll('.player-delete-row');
        const empty = document.getElementById('player-delete-empty');

        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            let visible = 0;

            rows.forEach((row) => {
                const matches = term === '' || row.dataset.search.includes(term);
                row.classList.toggle('hidden', !matches);
                if (matches) visible++;
            });

            empty.classList.toggle('hidden', visible > 0);
        });
    })();
</script>
@endsection
