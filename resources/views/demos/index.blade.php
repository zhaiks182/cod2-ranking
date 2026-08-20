@extends('layouts.app')

@section('title', 'Demos')

@section('content')
<div class="space-y-6">
    <h1 class="text-lg font-semibold">Demos</h1>

    @forelse($grouped as $date => $dayMatches)
        <section>
            <h2 class="text-xs uppercase tracking-wide text-slate-500 mb-2">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('l j \d\e F, Y') }}</h2>
            <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
                @foreach($dayMatches as $match)
                    <a href="{{ route('demos.show', $match) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-800/30">
                        <div class="flex items-center gap-3">
                            @if($mapImageUrl = \App\Support\MapImage::url($match->map))
                                <img src="{{ $mapImageUrl }}" alt="" class="h-12 w-12 rounded-lg object-cover shrink-0">
                            @endif
                            <div>
                                <div class="font-medium">{{ \App\Support\MapCatalog::mapLabel($match->map) }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ \App\Support\MapCatalog::gametypeLabel($match->gametype) }} · {{ $match->started_at->format('H:i') }}
                                    @if($match->final_score)
                                        · <span class="text-slate-300 font-medium">{{ $match->final_score }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="text-sm text-slate-400">{{ $match->demos_count }} demo(s) →</div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <p class="text-slate-500">Todavia no se subio ningun demo.</p>
    @endforelse

    {{ $matches->links() }}
</div>
@endsection
