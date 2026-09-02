@extends('layouts.app')

@section('title', __('Guardados'))

@section('content')
<div class="space-y-6">
    <div>
        <a href="{{ route('gallery.index') }}" class="text-xs text-blue-400 hover:text-blue-300">&larr; {{ __('Volver a la galería') }}</a>
        <h1 class="text-lg font-semibold flex items-center gap-2 mt-2"><span>🔖</span> {{ __('Guardados') }}</h1>
    </div>

    @if($items->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ __('Todavía no guardaste nada.') }}
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($items as $item)
                <a href="{{ route('gallery.show', $item) }}" class="rounded-xl border border-slate-800 bg-panel overflow-hidden hover:border-slate-600 transition-colors">
                    <div class="relative aspect-video bg-panel2 flex items-center justify-center overflow-hidden">
                        @if($item->type === 'image')
                            <img src="{{ $item->url() }}" alt="" loading="lazy" class="w-full h-full object-cover">
                        @else
                            <video src="{{ $item->url() }}#t=0.1" preload="metadata" muted playsinline class="w-full h-full object-cover pointer-events-none"></video>
                            <span class="absolute inset-0 flex items-center justify-center">
                                <span class="w-9 h-9 rounded-full bg-black/50 flex items-center justify-center text-white text-sm">▶</span>
                            </span>
                        @endif
                        @if($item->is_featured)
                            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-amber-500/90 text-black text-[11px] font-semibold">⭐ {{ __('Destacado') }}</span>
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
