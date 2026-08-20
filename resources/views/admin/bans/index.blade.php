@extends('layouts.admin')

@section('title', 'Bans')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Bans</h1>
        <p class="text-xs text-slate-500 mt-1">Se banea desde la consola en vivo (botón "Ban" junto a Kick). Acá se administra el historial y se desbanea.</p>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Jugador</th>
                    <th class="px-4 py-2 font-medium">Motivo</th>
                    <th class="px-4 py-2 font-medium">Baneado por</th>
                    <th class="px-4 py-2 font-medium">Fecha</th>
                    <th class="px-4 py-2 font-medium">Estado</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bans as $ban)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            @if($ban->player)
                                <a href="{{ route('players.show', $ban->player->guid) }}" class="hover:text-cyan-400" target="_blank">{!! \App\Support\Cod2Colors::toHtml($ban->player_name) !!}</a>
                            @else
                                {!! \App\Support\Cod2Colors::toHtml($ban->player_name) !!}
                            @endif
                            <div class="text-[10px] text-slate-600 font-mono">guid {{ $ban->guid }}</div>
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ $ban->reason ?: '—' }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $ban->bannedBy->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-400 whitespace-nowrap">{{ $ban->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">
                            @if($ban->is_active)
                                <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-red-950 text-red-400 border border-red-900">Activo</span>
                            @else
                                <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700">Desbaneado {{ $ban->unbanned_at->format('d/m/Y H:i') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if($ban->is_active)
                                <form method="POST" action="{{ route('admin.bans.destroy', $ban) }}" onsubmit="return confirm('¿Desbanear a {{ addslashes(\App\Support\Cod2Colors::stripColors($ban->player_name)) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Desbanear</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Sin bans todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $bans->links() }}
</div>
@endsection
