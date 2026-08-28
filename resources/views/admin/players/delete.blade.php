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

    @if($players->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            No hay jugadores registrados.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-3 py-2 font-medium">Jugador</th>
                        <th class="px-3 py-2 font-medium">guid</th>
                        <th class="px-3 py-2 font-medium">Alias usados</th>
                        <th class="px-3 py-2 font-medium text-right">Kills</th>
                        <th class="px-3 py-2 font-medium text-right">Deaths</th>
                        <th class="px-3 py-2 font-medium text-right">Visto</th>
                        <th class="px-3 py-2 font-medium text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($players as $player)
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
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
</script>
@endsection
