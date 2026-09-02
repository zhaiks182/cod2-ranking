@extends('layouts.admin')

@section('title', 'Galería')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Galería</h1>
        <p class="text-xs text-slate-500 mt-1">Videos e imágenes subidos por usuarios con sesión de Discord.</p>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800">
            <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Cuota por usuario</span>
        </div>
        <form method="POST" action="{{ route('admin.gallery.quota.update') }}" class="flex flex-wrap items-end gap-3 p-4">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-[11px] text-slate-500 mb-1">Cuota total</label>
                <input type="number" name="gallery_quota_mb" min="1" max="10000" value="{{ $setting->gallery_quota_mb ?? 100 }}"
                    class="bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm w-28">
                <span class="text-sm text-slate-400 ml-1">MB</span>
            </div>
            <div>
                <label class="block text-[11px] text-slate-500 mb-1">Máximo por video</label>
                <input type="number" name="gallery_video_max_mb" min="1" max="10000" value="{{ $setting->gallery_video_max_mb ?? 30 }}"
                    class="bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm w-28">
                <span class="text-sm text-slate-400 ml-1">MB</span>
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">Guardar</button>
            <p class="text-xs text-slate-500 basis-full">Cuota: cuánto puede almacenar cada usuario, acumulado entre todos sus archivos. Máximo por video: tope aparte que se aplica solo a video, además de la cuota.</p>
        </form>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Título</th>
                    <th class="px-4 py-2 font-medium">Usuario</th>
                    <th class="px-4 py-2 font-medium">Tipo</th>
                    <th class="px-4 py-2 font-medium text-right">Tamaño</th>
                    <th class="px-4 py-2 font-medium text-right">Comentarios</th>
                    <th class="px-4 py-2 font-medium">Fecha</th>
                    <th class="px-4 py-2 font-medium text-right"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ route('admin.gallery.show', $item) }}" class="hover:text-cyan-400">{{ $item->title }}</a>
                            @if($item->is_featured)<span class="ml-1 text-amber-400" title="Destacado">⭐</span>@endif
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ $item->siteUser->discord_username }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $item->type === 'video' ? 'Video' : 'Imagen' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($item->size_bytes / 1024 / 1024, 1) }} MB</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $item->comments_count }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $item->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <form method="POST" action="{{ route('admin.gallery.toggle-featured', $item) }}" class="inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-amber-500 hover:text-amber-400">{{ $item->is_featured ? 'Quitar destaque' : 'Destacar' }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.gallery.destroy', $item) }}" onsubmit="return confirm('¿Borrar &quot;{{ $item->title }}&quot;? No se puede deshacer.')" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-red-500 hover:text-red-400">Borrar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Todavía no se subió nada.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $items->links() }}
</div>
@endsection
