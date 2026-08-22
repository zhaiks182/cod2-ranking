@extends('layouts.admin')

@section('title', 'Respaldos')

@section('content')
@php
    $totalMb = $totalBytes / 1048576;
    $totalLabel = $totalMb >= 1024 ? number_format($totalMb / 1024, 2).' GB' : number_format($totalMb, 1).' MB';
@endphp
<div class="space-y-4">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">Respaldos</h1>
            <p class="text-xs text-slate-500 mt-1">Volcado completo de la base de datos (mysqldump comprimido) — todos los módulos (partidas, jugadores, demos, bans, auditoría, configuración). Se crea uno automático por día y se borran los que tengan más de 10 días.</p>
        </div>
        <form method="POST" action="{{ route('admin.backups.store') }}">
            @csrf
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium whitespace-nowrap">Crear respaldo ahora</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4">
        <h2 class="text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Importar base de datos</h2>
        <p class="text-xs text-slate-500 mb-3">Para instalar el panel en un server nuevo sin respaldos locales todavía — subí un <code class="text-cyan-300">.sql</code> o <code class="text-cyan-300">.sql.gz</code> (por ejemplo, uno bajado con "Descargar" desde otro server) y se importa entero. Funciona incluso con la base de datos vacía. Límite: 25 MB.</p>
        <form method="POST" action="{{ route('admin.backups.import') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3"
            onsubmit="return confirm('¿Importar este archivo? Esto REEMPLAZA toda la base de datos actual. Se guarda un respaldo del estado actual antes de importar, por las dudas.\n\n¿Confirmar?')">
            @csrf
            <input type="file" name="dump" accept=".sql,.gz" required class="text-xs text-slate-300 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-panel2 file:text-slate-200 file:text-xs hover:file:bg-slate-700">
            <button type="submit" class="px-3 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium whitespace-nowrap">Importar</button>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
            <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Total guardado</span>
            <span class="text-sm tabular-nums text-slate-300">
                <span class="font-semibold text-cyan-400">{{ $totalLabel }}</span>
                <span class="text-slate-500">· {{ $backups->count() }} respaldo(s)</span>
            </span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Archivo</th>
                    <th class="px-4 py-2 font-medium">Fecha</th>
                    <th class="px-4 py-2 font-medium text-right">Tamaño</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($backups as $b)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-mono text-xs text-slate-300">{{ $b->name }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $b->date->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($b->size / 1024, 1) }} KB</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <a href="{{ route('admin.backups.download', $b->name) }}" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Descargar</a>
                            <form method="POST" action="{{ route('admin.backups.restore', $b->name) }}" class="inline" onsubmit="return confirm('¿Restaurar la base de datos completa desde {{ $b->name }}?\n\nEsto REEMPLAZA todo lo actual (partidas, jugadores, demos, bans, todo) por lo que había en ese momento. Se pierde cualquier cosa que haya pasado después. Se guarda un respaldo del estado actual antes de restaurar, por las dudas.\n\n¿Confirmar?')">
                                @csrf
                                <button type="submit" class="text-xs px-2 py-1 rounded border border-amber-900 text-amber-400 hover:bg-amber-950/40">Restaurar</button>
                            </form>
                            <form method="POST" action="{{ route('admin.backups.destroy', $b->name) }}" class="inline" onsubmit="return confirm('¿Borrar el respaldo {{ $b->name }}? No se puede deshacer.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-red-500 hover:text-red-400">Borrar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Todavía no hay respaldos.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
