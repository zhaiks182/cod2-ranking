@extends('layouts.app')

@section('title', __('Galería'))

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-semibold flex items-center gap-2"><span>🎬</span> {{ __('Galería') }}</h1>
        @auth('site')
            <a href="{{ route('gallery.create') }}" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">{{ __('Subir video/imagen') }}</a>
        @else
            <a href="{{ route('login') }}" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">{{ __('Iniciar sesión para subir') }}</a>
        @endauth
    </div>

    <div class="flex items-center gap-2 text-sm">
        <a href="{{ route('gallery.index') }}" class="px-3 py-1.5 rounded-lg border {{ !$type ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ __('Todo') }}</a>
        <a href="{{ route('gallery.index', ['tipo' => 'video']) }}" class="px-3 py-1.5 rounded-lg border {{ $type === 'video' ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ __('Videos') }}</a>
        <a href="{{ route('gallery.index', ['tipo' => 'image']) }}" class="px-3 py-1.5 rounded-lg border {{ $type === 'image' ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ __('Imágenes') }}</a>
    </div>

    @if($items->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ __('Todavía no se subió nada.') }}
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($items as $item)
                <a href="{{ route('gallery.show', $item) }}" class="rounded-xl border border-slate-800 bg-panel overflow-hidden hover:border-slate-600 transition-colors">
                    <div class="relative aspect-video bg-panel2 flex items-center justify-center overflow-hidden">
                        @if($item->type === 'image')
                            <img src="{{ $item->url() }}" alt="" loading="lazy" class="w-full h-full object-cover">
                        @else
                            {{-- Sin generar miniaturas en el servidor (necesitaria ffmpeg,
                            riesgoso en un VPS de 1 core, ver la spec) -- el propio navegador
                            muestra el primer frame como miniatura con preload="metadata",
                            pidiendole a Apache solo un rango chico del archivo (HTTP Range,
                            ya soportado nativo), no el video entero. --}}
                            <video src="{{ $item->url() }}#t=0.1" preload="metadata" muted playsinline class="w-full h-full object-cover pointer-events-none"></video>
                            <span class="absolute inset-0 flex items-center justify-center">
                                <span class="w-9 h-9 rounded-full bg-black/50 flex items-center justify-center text-white text-sm">▶</span>
                            </span>
                        @endif
                    </div>
                    <div class="p-3">
                        <div class="text-sm font-medium truncate">{{ $item->title }}</div>
                        <div class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                            @if($item->siteUser->avatar_url)
                                <img src="{{ $item->siteUser->avatar_url }}" alt="" class="w-4 h-4 rounded-full">
                            @endif
                            <span class="truncate">{{ $item->siteUser->discord_username }}</span>
                        </div>
                        <div class="text-[11px] text-slate-500 mt-1.5 flex items-center gap-3">
                            <span>❤️ {{ $item->likes_count }}</span>
                            <span>💬 {{ $item->comments_count }}</span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        {{ $items->links() }}
    @endif
</div>
@endsection
