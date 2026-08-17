@extends('layouts.admin')

@section('title', 'Países')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Países</h1>
        <p class="text-xs text-slate-500 mt-1">
            País detectado por la última IP vista de cada jugador. Si alguien usó VPN (o es una
            cuenta duplicada con un nombre mal parseado) y el país que muestra no tiene sentido,
            quitale la IP acá — el jugador deja de aparecer en <code>/paises</code> hasta que
            vuelva a conectarse.
        </p>
    </div>

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay jugadores con IP conocida.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">Jugador</th>
                        <th class="px-4 py-2 font-medium">País</th>
                        <th class="px-4 py-2 font-medium">IP</th>
                        <th class="px-4 py-2 font-medium text-right">Bajas</th>
                        <th class="px-4 py-2 font-medium text-right">Visto</th>
                        <th class="px-4 py-2 font-medium text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 font-medium">
                                <a href="{{ route('players.show', $row->player->guid) }}" target="_blank" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                            </td>
                            <td class="px-4 py-2">
                                @if($row->country)
                                    <span title="{{ $row->country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($row->country['code']) !!}</span>
                                    {{ $row->country['name'] }}
                                @else
                                    <span class="text-slate-600">Sin resolver</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-slate-400 font-mono text-xs">{{ $row->player->ip }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->player->kills_total }}</td>
                            <td class="px-4 py-2 text-right text-slate-500 text-xs">{{ $row->player->last_seen_at?->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('admin.players.clear-ip', $row->player) }}" onsubmit="return confirm('¿Quitar el país de {{ $row->player->last_name_plain }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:underline">Quitar</button>
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
@endsection
