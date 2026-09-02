@extends('layouts.admin')

@section('title', $galleryItem->title)

@section('content')
<div class="max-w-2xl space-y-4">
    <a href="{{ route('admin.gallery.index') }}" class="text-xs text-cyan-400 hover:underline">&larr; Volver a Galería</a>

    <div>
        <h1 class="text-lg font-semibold">{{ $galleryItem->title }}</h1>
        <p class="text-xs text-slate-500 mt-1">
            Subido por {{ $galleryItem->siteUser->discord_username }} el {{ $galleryItem->created_at->format('d/m/Y H:i') }}
            · <a href="{{ route('gallery.show', $galleryItem) }}" class="text-cyan-400 hover:underline" target="_blank">Ver en el sitio</a>
        </p>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800">
            <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Comentarios ({{ $comments->count() }})</span>
        </div>
        <div class="divide-y divide-slate-800/60">
            @forelse($comments as $comment)
                <div class="px-4 py-3 flex items-start justify-between gap-3">
                    <div class="text-sm">
                        <span class="font-medium text-slate-300">{{ $comment->siteUser->discord_username }}</span>
                        <span class="text-slate-500 text-xs ml-1">{{ $comment->created_at->format('d/m/Y H:i') }}</span>
                        <p class="text-slate-300 mt-0.5">{{ $comment->body }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.gallery.comments.destroy', $comment) }}" onsubmit="return confirm('¿Borrar este comentario?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs px-2 py-1 rounded border border-slate-700 hover:border-red-500 hover:text-red-400 shrink-0">Borrar</button>
                    </form>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-slate-500 text-sm">Sin comentarios.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
