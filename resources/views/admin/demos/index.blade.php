@extends('layouts.admin')

@section('title', 'Demos')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Demos</h1>
        <p class="text-xs text-slate-500 mt-1">Demos subidos automáticamente por los jugadores al terminar cada partida SD.</p>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4">
        <form method="POST" action="{{ route('admin.settings.update') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Retención de demos</label>
                <select name="demo_retention_days" class="bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm">
                    <option value="" {{ $setting->demo_retention_days === null ? 'selected' : '' }}>Sin límite</option>
                    @foreach([3, 5, 10, 20, 30, 60, 90] as $days)
                        <option value="{{ $days }}" {{ $setting->demo_retention_days === $days ? 'selected' : '' }}>{{ $days }} días</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Guardar</button>
            <p class="text-xs text-slate-500 basis-full">Los demos más viejos que este límite se borran automáticamente (archivo y registro) una vez por día. No se pueden recuperar.</p>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Mapa</th>
                    <th class="px-4 py-2 font-medium">Modo</th>
                    <th class="px-4 py-2 font-medium">Fecha</th>
                    <th class="px-4 py-2 font-medium text-right">Demos</th>
                    <th class="px-4 py-2 font-medium text-right">Tamaño total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($matches as $match)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ route('admin.demos.show', $match) }}" class="hover:text-cyan-400">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $match->started_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $match->demos_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($match->demos_sum_size_bytes / 1024 / 1024, 1) }} MB</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">Todavia no se subio ningun demo.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $matches->links() }}
</div>
@endsection
