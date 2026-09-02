@extends('layouts.app')

@section('title', $galleryItem->title.' — Galería')
@section('og_title', $galleryItem->title)
@section('og_description', __('Subido por :name en la galería de CoD2 Stats — Pug Latam · :likes 👍 · :comments 💬', ['name' => $galleryItem->siteUser->discord_username, 'likes' => $likesCount, 'comments' => $galleryItem->comments->count()]))
@if($galleryItem->type === 'image')
    @section('og_image', $galleryItem->url())
@endif

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <a href="{{ route('gallery.index') }}" class="text-xs text-blue-400 hover:text-blue-300">&larr; {{ __('Volver a la galería') }}</a>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="bg-black">
            @if($galleryItem->type === 'video')
                <video src="{{ $galleryItem->url() }}" controls class="w-full max-h-[70vh]"></video>
            @else
                <img src="{{ $galleryItem->url() }}" alt="{{ $galleryItem->title }}" class="w-full max-h-[70vh] object-contain mx-auto">
            @endif
        </div>

        <div class="p-4 space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold flex items-center gap-2">
                        {{ $galleryItem->title }}
                        @if($galleryItem->is_featured)
                            <span class="px-2 py-0.5 rounded-full bg-amber-500/90 text-black text-[11px] font-semibold">⭐ {{ __('Destacado') }}</span>
                        @endif
                    </h1>
                    <div class="text-sm text-slate-400 flex items-center gap-1.5 mt-1">
                        @if($galleryItem->siteUser->avatar_url)
                            <img src="{{ $galleryItem->siteUser->avatar_url }}" alt="" class="w-5 h-5 rounded-full">
                        @endif
                        {{ $galleryItem->siteUser->discord_username }}
                        <span class="text-slate-600">·</span>
                        {{ $galleryItem->created_at->format('d/m/Y H:i') }}
                    </div>
                    @if($galleryItem->match)
                        <a href="{{ route('matches.show', $galleryItem->match) }}" class="inline-block mt-1 text-xs text-cyan-400 hover:underline">
                            🎮 {{ \App\Support\MapCatalog::mapLabel($galleryItem->match->map) }} — {{ __('ver partida') }}
                        </a>
                    @endif
                </div>

                @auth('site')
                    @if($galleryItem->site_user_id === auth('site')->id())
                        <div class="flex items-center gap-3 shrink-0">
                            <a href="{{ route('gallery.edit', $galleryItem) }}" class="text-xs text-cyan-400 hover:text-cyan-300">{{ __('Editar') }}</a>
                            <form method="POST" action="{{ route('gallery.destroy', $galleryItem) }}" onsubmit="return confirm('¿Borrar este archivo?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-400 hover:text-red-300">{{ __('Borrar') }}</button>
                            </form>
                        </div>
                    @endif
                @endauth
            </div>

            <div class="flex items-center gap-3">
                @auth('site')
                    <form method="POST" action="{{ route('gallery.like', $galleryItem) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border {{ $liked ? 'border-red-500 text-red-400' : 'border-slate-700 text-slate-300 hover:border-slate-500' }} text-sm">
                            {{ $liked ? '❤️' : '🤍' }} {{ $likesCount }}
                        </button>
                    </form>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-slate-400 text-sm">🤍 {{ $likesCount }}</span>
                @endauth
                <button type="button" onclick="cod2CopyConnect(this, {{ json_encode(route('gallery.show', $galleryItem)) }})"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:border-slate-500 text-sm">
                    🔗 {{ __('Compartir') }}
                </button>
                <a href="{{ $galleryItem->url() }}" download="{{ \Illuminate\Support\Str::slug($galleryItem->title) }}.{{ pathinfo($galleryItem->file_path, PATHINFO_EXTENSION) }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:border-slate-500 text-sm">
                    ⬇️ {{ __('Descargar') }}
                </a>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4 space-y-4">
        <h2 class="text-sm uppercase tracking-wide text-slate-500 font-semibold">{{ __('Comentarios') }} ({{ $galleryItem->comments->count() }})</h2>

        @auth('site')
            <form method="POST" action="{{ route('gallery.comments.store', $galleryItem) }}" class="space-y-2">
                @csrf
                <textarea name="body" required maxlength="500" rows="2" placeholder="{{ __('Escribí un comentario…') }}"
                    class="w-full rounded-lg bg-panel2 border border-slate-700 px-3 py-2 text-sm text-slate-200"></textarea>
                @error('body')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold">{{ __('Comentar') }}</button>
            </form>
        @else
            <p class="text-xs text-slate-500"><a href="{{ route('login') }}" class="text-cyan-400 hover:underline">{{ __('Iniciá sesión') }}</a> {{ __('para comentar.') }}</p>
        @endauth

        <div class="space-y-3">
            @forelse($galleryItem->comments as $comment)
                <div class="flex items-start justify-between gap-2 border-t border-slate-800 pt-3 first:border-t-0 first:pt-0">
                    <div class="text-sm">
                        <span class="font-medium text-slate-300">{{ $comment->siteUser->discord_username }}</span>
                        <span class="text-slate-500 text-xs ml-1">{{ $comment->created_at->diffForHumans() }}</span>
                        <p class="text-slate-300 mt-0.5">{{ $comment->body }}</p>
                    </div>
                    @auth('site')
                        @if($comment->site_user_id === auth('site')->id() || $galleryItem->site_user_id === auth('site')->id())
                            <form method="POST" action="{{ route('gallery.comments.destroy', $comment) }}" onsubmit="return confirm('¿Borrar este comentario?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-400 hover:text-red-300 shrink-0">{{ __('Borrar') }}</button>
                            </form>
                        @endif
                    @endauth
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ __('Todavía no hay comentarios.') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
