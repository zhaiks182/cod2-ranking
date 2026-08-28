@extends('layouts.admin')

@section('title', 'Íconos de jugadores')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Íconos de jugadores</h1>
        <p class="text-xs text-slate-500 mt-1">
            Sube un ícono personalizado para que aparezca al lado del nombre del jugador en el
            top 3 del ranking y del dashboard (mismo lugar donde vive el burro de dtN.harek).
            Cualquier imagen sirve — se re-escala automáticamente a un tamaño chico al subirla,
            no hace falta prepararla vos mismo.
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">{{ $errors->first() }}</div>
    @endif

    @if($players->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            No hay jugadores registrados.
        </div>
    @else
        <input type="text" id="player-icon-search" placeholder="Buscar por nombre, alias o guid…"
            class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 text-sm focus:outline-none focus:border-gsaccent">

        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-3 py-2 font-medium">Ícono</th>
                        <th class="px-3 py-2 font-medium">Jugador</th>
                        <th class="px-3 py-2 font-medium">guid</th>
                        <th class="px-3 py-2 font-medium">Subir / reemplazar</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($players as $player)
                        @php $aliasNames = $player->aliases->pluck('name_plain')->unique(); @endphp
                        <tr class="player-icon-row border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30"
                            data-search="{{ mb_strtolower($player->last_name_plain.' '.$player->guid.' '.$aliasNames->implode(' ')) }}">
                            <td class="px-3 py-2">
                                @if($player->icon_url)
                                    <img src="{{ $player->icon_url }}" alt="" class="h-8 w-8 object-contain">
                                @else
                                    <span class="text-slate-600">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-medium">
                                <a href="{{ route('players.show', $player->guid) }}" target="_blank" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</a>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-400">{{ $player->guid }}</td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('admin.players.icons.store', $player) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                                    @csrf
                                    <input type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp" required
                                        class="text-xs text-slate-400 file:mr-2 file:rounded-lg file:border file:border-slate-700 file:bg-slate-900 file:px-2 file:py-1 file:text-xs file:text-slate-300 hover:file:border-cyan-500">
                                    <button type="submit" class="shrink-0 text-xs px-2 py-1 rounded-lg border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Subir</button>
                                </form>
                            </td>
                            <td class="px-3 py-2 text-right">
                                @if($player->icon_path)
                                    <form method="POST" action="{{ route('admin.players.icons.destroy', $player) }}" onsubmit="return confirm('¿Quitar el ícono de {{ $player->last_name_plain }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-red-400 hover:underline">Quitar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <p id="player-icon-empty" class="hidden text-center text-sm text-slate-500 py-6">
            Ningún jugador coincide con esa búsqueda.
        </p>
    @endif
</div>

<script>
    (function () {
        const input = document.getElementById('player-icon-search');
        if (!input) return;

        const rows = document.querySelectorAll('.player-icon-row');
        const empty = document.getElementById('player-icon-empty');

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
