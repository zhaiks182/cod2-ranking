@extends('layouts.admin')

@section('title', 'Clanes')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Clanes</h1>
        <p class="text-xs text-slate-500 mt-1">
            Disolución forzada para intervenir ante abuso (nombre ofensivo, etc.) sin depender
            de que el fundador coopere. <strong>No se puede deshacer.</strong>
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if($clans->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            No hay clanes creados.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">Clan</th>
                        <th class="px-4 py-2 font-medium">Fundador</th>
                        <th class="px-4 py-2 font-medium">Miembros</th>
                        <th class="px-4 py-2 font-medium">Fundado</th>
                        <th class="px-4 py-2 font-medium text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @foreach($clans as $clan)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('clans.show', $clan) }}" target="_blank" class="text-cyan-400 hover:underline">[{{ $clan->tag }}] {{ $clan->name }}</a></td>
                            <td class="px-4 py-2 text-slate-400">{{ $clan->founder->player->last_name_plain ?? $clan->founder->discord_username }}</td>
                            <td class="px-4 py-2 text-slate-400">{{ $clan->members_count }}</td>
                            <td class="px-4 py-2 text-slate-400">{{ $clan->created_at->format('d/m/Y') }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('admin.clans.destroy', $clan) }}" onsubmit="return confirm('¿Disolver \'{{ $clan->name }}\'? Esto borra el clan, todos sus miembros e invitaciones. No se puede deshacer.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:text-red-300">Disolver</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
