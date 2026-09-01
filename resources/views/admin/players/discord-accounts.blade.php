@extends('layouts.admin')

@section('title', 'Cuentas de Discord')

@section('content')
<div class="space-y-4">
    <h1 class="text-lg font-semibold">Cuentas de Discord</h1>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-panel">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2">Discord</th>
                    <th class="px-4 py-2">Jugador vinculado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($siteUsers as $siteUser)
                    <tr class="border-b border-slate-800/60">
                        <td class="px-4 py-2">{{ $siteUser->discord_username }}</td>
                        <td class="px-4 py-2">
                            @if($siteUser->player)
                                <a href="{{ route('players.show', $siteUser->player) }}" class="text-cyan-400 hover:underline">{{ $siteUser->player->last_name_plain }}</a>
                            @else
                                <span class="text-slate-500">Sin reclamar</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if($siteUser->player)
                                <form method="POST" action="{{ route('admin.players.discord-accounts.unlink', $siteUser) }}" onsubmit="return confirm('¿Desvincular esta cuenta del jugador?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:underline">Desvincular</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Todavía no hay ninguna cuenta de Discord registrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
