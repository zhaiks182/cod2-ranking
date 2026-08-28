@extends('layouts.admin')

@section('title', 'Fusionar jugadores')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Fusionar jugadores</h1>
        <p class="text-xs text-slate-500 mt-1">
            Para cuando el mismo jugador real termina con varios perfiles (el <code>guid</code> cambia
            entre sesiones — HWID inestable, reinstalación de CoD2x, etc.). Buscá por cualquier nombre
            que haya usado (actual o alias viejo), tildá los perfiles que sean la misma persona, elegí
            cuál queda como destino, y confirmá. Se suman los kills/deaths/stats de todos dentro del
            destino, se mueven los alias, demos, bans y mensajes de chat, y los perfiles fuente se
            borran. <strong>No se puede deshacer.</strong>
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('admin.players.merge.index') }}" class="flex gap-2">
        <input type="text" name="q" value="{{ $query }}" placeholder="Nombre o alias, ej. MOKOS"
            class="flex-1 rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 text-sm focus:outline-none focus:border-gsaccent">
        <button type="submit" class="rounded-lg bg-gsprimary px-4 py-2 text-sm font-medium hover:bg-gsprimary/80">Buscar</button>
    </form>

    @if($query !== '' && $results->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Ningún jugador tiene ese nombre ni ese alias.
        </div>
    @elseif($results->isNotEmpty())
        <form method="POST" action="{{ route('admin.players.merge.store') }}" id="merge-form"
            onsubmit="return confirm('¿Fusionar los perfiles marcados? No se puede deshacer.')">
            @csrf

            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-3 py-2 font-medium w-8"></th>
                            <th class="px-3 py-2 font-medium w-8">Destino</th>
                            <th class="px-3 py-2 font-medium">Jugador</th>
                            <th class="px-3 py-2 font-medium">guid</th>
                            <th class="px-3 py-2 font-medium">Alias usados</th>
                            <th class="px-3 py-2 font-medium text-right">Kills</th>
                            <th class="px-3 py-2 font-medium text-right">Deaths</th>
                            <th class="px-3 py-2 font-medium text-right">Visto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results as $player)
                            <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="source_ids[]" value="{{ $player->id }}" class="merge-checkbox" data-kills="{{ $player->kills_total }}" data-deaths="{{ $player->deaths_total }}">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="radio" name="target_id" value="{{ $player->id }}">
                                </td>
                                <td class="px-3 py-2 font-medium">
                                    <a href="{{ route('players.show', $player->guid) }}" target="_blank" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</a>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-slate-400">{{ $player->guid }}</td>
                                <td class="px-3 py-2 text-xs text-slate-400">
                                    {{ $player->aliases->pluck('name_plain')->unique()->take(6)->implode(', ') }}
                                    @if($player->aliases->pluck('name_plain')->unique()->count() > 6)
                                        …
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-400">{{ $player->kills_total }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-400">{{ $player->deaths_total }}</td>
                                <td class="px-3 py-2 text-right text-slate-500 text-xs">
                                    {{ $player->first_seen_at?->toDateString() }} → {{ $player->last_seen_at?->toDateString() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between rounded-xl border border-slate-800 bg-panel px-4 py-3 text-sm">
                <div class="text-slate-400">
                    Seleccionados: <span id="merge-count" class="text-slate-200 font-medium">0</span>
                    — Kills combinados: <span id="merge-kills" class="text-slate-200 font-medium">0</span>
                    — Deaths combinados: <span id="merge-deaths" class="text-slate-200 font-medium">0</span>
                </div>
                <button type="submit" class="rounded-lg bg-red-900/60 border border-red-800 px-4 py-2 text-sm font-medium text-red-200 hover:bg-red-900">
                    Fusionar seleccionados
                </button>
            </div>
        </form>

        <script>
            (function () {
                const checkboxes = document.querySelectorAll('.merge-checkbox');
                const countEl = document.getElementById('merge-count');
                const killsEl = document.getElementById('merge-kills');
                const deathsEl = document.getElementById('merge-deaths');

                function update() {
                    let count = 0, kills = 0, deaths = 0;
                    checkboxes.forEach((cb) => {
                        if (cb.checked) {
                            count++;
                            kills += parseInt(cb.dataset.kills || '0', 10);
                            deaths += parseInt(cb.dataset.deaths || '0', 10);
                        }
                    });
                    countEl.textContent = count;
                    killsEl.textContent = kills;
                    deathsEl.textContent = deaths;
                }

                checkboxes.forEach((cb) => cb.addEventListener('change', update));
            })();
        </script>
    @endif
</div>
@endsection
