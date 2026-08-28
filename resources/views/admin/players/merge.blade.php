@extends('layouts.admin')

@section('title', 'Fusionar jugadores')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Fusionar jugadores</h1>
        <p class="text-xs text-slate-500 mt-1">
            Para cuando el mismo jugador real termina con varios perfiles (el <code>guid</code> cambia
            entre sesiones — HWID inestable, reinstalación de CoD2x, etc.). Buscá por cualquier nombre
            que haya usado (actual o alias viejo) y arrastrá las tarjetas que sean la misma persona al
            grupo de la derecha — o tocá el <span class="font-mono">+</span> si preferís no arrastrar.
            Marcá cuál queda como destino con la estrella. Se suman los kills/deaths/stats de todos
            dentro del destino, se mueven los alias, demos, bans y mensajes de chat, y los perfiles
            fuente se borran. <strong>No se puede deshacer.</strong>
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
        <div id="merge-app" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Pool: resultados de la búsqueda, cada uno arrastrable --}}
            <div>
                <h2 class="text-xs uppercase tracking-wide text-slate-500 mb-2">Resultados ({{ $results->count() }})</h2>
                <div id="merge-pool" class="space-y-2 max-h-[32rem] overflow-y-auto pr-1">
                    @foreach($results as $player)
                        <div class="merge-card rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2 cursor-grab active:cursor-grabbing hover:border-gsaccent/60"
                            draggable="true"
                            data-id="{{ $player->id }}"
                            data-name="{{ $player->last_name_plain }}"
                            data-name-html="{{ \App\Support\Cod2Colors::toHtml($player->last_name) }}"
                            data-guid="{{ $player->guid }}"
                            data-kills="{{ $player->kills_total }}"
                            data-deaths="{{ $player->deaths_total }}"
                            data-url="{{ route('players.show', $player->guid) }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-medium truncate">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</div>
                                    <div class="font-mono text-[11px] text-slate-500">guid {{ $player->guid }}</div>
                                    <div class="text-[11px] text-slate-500 truncate">
                                        {{ $player->aliases->pluck('name_plain')->unique()->take(5)->implode(', ') }}
                                    </div>
                                </div>
                                <div class="shrink-0 flex items-center gap-2">
                                    <div class="text-right text-[11px] text-slate-400 tabular-nums">
                                        {{ $player->kills_total }}K / {{ $player->deaths_total }}D
                                    </div>
                                    <button type="button" class="merge-add rounded-full w-6 h-6 leading-none text-cyan-400 border border-cyan-800 hover:bg-cyan-900/40" title="Agregar al grupo">+</button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Grupo a fusionar: drop zone --}}
            <div>
                <h2 class="text-xs uppercase tracking-wide text-slate-500 mb-2">Grupo a fusionar</h2>
                <div id="merge-dropzone" class="min-h-[10rem] rounded-lg border-2 border-dashed border-slate-700 bg-panel px-3 py-3 space-y-2">
                    <p id="merge-dropzone-empty" class="text-sm text-slate-500 text-center py-8">
                        Arrastrá acá los perfiles que sean la misma persona<br>
                        <span class="text-xs">(o tocá el + de cada tarjeta)</span>
                    </p>
                </div>

                <div class="mt-3 rounded-xl border border-slate-800 bg-panel px-4 py-3 text-sm space-y-2">
                    <div class="text-slate-400">
                        Seleccionados: <span id="merge-count" class="text-slate-200 font-medium">0</span>
                        — Kills combinados: <span id="merge-kills" class="text-slate-200 font-medium">0</span>
                        — Deaths combinados: <span id="merge-deaths" class="text-slate-200 font-medium">0</span>
                    </div>
                    <form method="POST" action="{{ route('admin.players.merge.store') }}" id="merge-form"
                        onsubmit="return confirm('¿Fusionar los perfiles marcados? No se puede deshacer.')">
                        @csrf
                        <div id="merge-inputs"></div>
                        <button type="submit" id="merge-submit" disabled
                            class="w-full rounded-lg bg-red-900/60 border border-red-800 px-4 py-2 text-sm font-medium text-red-200 hover:bg-red-900 disabled:opacity-40 disabled:cursor-not-allowed">
                            Fusionar grupo
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const pool = document.getElementById('merge-pool');
                const dropzone = document.getElementById('merge-dropzone');
                const empty = document.getElementById('merge-dropzone-empty');
                const countEl = document.getElementById('merge-count');
                const killsEl = document.getElementById('merge-kills');
                const deathsEl = document.getElementById('merge-deaths');
                const inputsEl = document.getElementById('merge-inputs');
                const submitBtn = document.getElementById('merge-submit');

                const group = new Map(); // id -> {name, guid, kills, deaths}
                let targetId = null;

                function cardData(card) {
                    return {
                        id: card.dataset.id,
                        nameHtml: card.dataset.nameHtml,
                        guid: card.dataset.guid,
                        kills: parseInt(card.dataset.kills || '0', 10),
                        deaths: parseInt(card.dataset.deaths || '0', 10),
                    };
                }

                function addToGroup(card) {
                    const data = cardData(card);
                    if (group.has(data.id)) return;
                    group.set(data.id, data);
                    if (!targetId) targetId = data.id; // el primero entra como destino por default
                    card.classList.add('hidden');
                    render();
                }

                function removeFromGroup(id) {
                    group.delete(id);
                    if (targetId === id) targetId = group.size ? group.keys().next().value : null;
                    const card = pool.querySelector('.merge-card[data-id="' + id + '"]');
                    if (card) card.classList.remove('hidden');
                    render();
                }

                function render() {
                    dropzone.querySelectorAll('.merge-chip').forEach((el) => el.remove());
                    empty.classList.toggle('hidden', group.size > 0);

                    let kills = 0, deaths = 0;
                    group.forEach((data) => {
                        kills += data.kills;
                        deaths += data.deaths;

                        const chip = document.createElement('div');
                        const isTarget = data.id === targetId;
                        chip.className = 'merge-chip rounded-lg border px-3 py-2 flex items-center justify-between gap-2 ' +
                            (isTarget ? 'border-amber-500 bg-amber-950/20' : 'border-slate-700 bg-slate-900/60');
                        chip.innerHTML =
                            '<div class="min-w-0">' +
                                '<div class="font-medium truncate">' + data.nameHtml + '</div>' +
                                '<div class="font-mono text-[11px] text-slate-500">guid ' + data.guid + ' — ' + data.kills + 'K / ' + data.deaths + 'D</div>' +
                            '</div>' +
                            '<div class="shrink-0 flex items-center gap-1.5">' +
                                '<button type="button" data-star class="text-lg leading-none ' + (isTarget ? 'text-amber-400' : 'text-slate-600 hover:text-amber-400') + '" title="Usar como destino">★</button>' +
                                '<button type="button" data-remove class="w-6 h-6 rounded-full leading-none text-slate-400 border border-slate-700 hover:bg-slate-800" title="Quitar del grupo">×</button>' +
                            '</div>';

                        chip.querySelector('[data-star]').addEventListener('click', () => { targetId = data.id; render(); });
                        chip.querySelector('[data-remove]').addEventListener('click', () => removeFromGroup(data.id));

                        dropzone.appendChild(chip);
                    });

                    countEl.textContent = group.size;
                    killsEl.textContent = kills;
                    deathsEl.textContent = deaths;
                    submitBtn.disabled = group.size < 2 || !targetId;

                    inputsEl.innerHTML = '';
                    group.forEach((data, id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'source_ids[]';
                        input.value = id;
                        inputsEl.appendChild(input);
                    });
                    if (targetId) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'target_id';
                        input.value = targetId;
                        inputsEl.appendChild(input);
                    }
                }

                pool.querySelectorAll('.merge-card').forEach((card) => {
                    card.addEventListener('dragstart', (e) => {
                        e.dataTransfer.setData('text/plain', card.dataset.id);
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    card.querySelector('.merge-add').addEventListener('click', () => addToGroup(card));
                });

                dropzone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropzone.classList.add('border-gsaccent');
                });
                dropzone.addEventListener('dragleave', () => dropzone.classList.remove('border-gsaccent'));
                dropzone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dropzone.classList.remove('border-gsaccent');
                    const id = e.dataTransfer.getData('text/plain');
                    const card = pool.querySelector('.merge-card[data-id="' + id + '"]');
                    if (card) addToGroup(card);
                });

                render();
            })();
        </script>
    @endif
</div>
@endsection
