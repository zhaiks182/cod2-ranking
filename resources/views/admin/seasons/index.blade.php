@extends('layouts.admin')

@section('title', 'Temporadas')

@section('content')
<div class="space-y-4">
    <h1 class="text-lg font-semibold">Temporadas</h1>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <form method="POST" action="{{ route('admin.seasons.store') }}" class="p-4 flex flex-wrap items-end gap-3"
            onsubmit="return confirm('¿Cerrar la temporada activa e iniciar una nueva? Las partidas nuevas van a contar para la temporada nueva; la data de la temporada actual queda intacta y disponible.')">
            @csrf
            <div>
                <label for="name" class="block text-xs text-slate-500 mb-1">Nombre de la temporada nueva</label>
                <input type="text" name="name" id="name" required maxlength="100"
                    class="w-64 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm">
                @error('name')
                    <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Cerrar temporada actual e iniciar esta</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Nombre</th>
                    <th class="px-4 py-2 font-medium">Desde</th>
                    <th class="px-4 py-2 font-medium">Hasta</th>
                    <th class="px-4 py-2 font-medium">Partidas</th>
                    <th class="px-4 py-2 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($seasons as $season)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">{{ $season->name }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $season->started_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">
                            @if($season->ended_at)
                                <span class="text-slate-400">{{ $season->ended_at->format('d/m/Y H:i') }}</span>
                            @else
                                <span class="text-emerald-400 text-xs font-medium">— activa —</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-400 tabular-nums">{{ $season->matches_count }}</td>
                        <td class="px-4 py-2 text-right">
                            @if($season->ended_at)
                                <form method="POST" action="{{ route('admin.seasons.reactivate', $season) }}"
                                    onsubmit="return confirm('¿Reactivar \'{{ $season->name }}\'? Esto cierra la temporada activa ahora mismo; las partidas nuevas van a contar para \'{{ $season->name }}\' de nuevo.')">
                                    @csrf
                                    <button type="submit" class="px-2 py-1 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-500 hover:text-cyan-400 text-xs">Reactivar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
