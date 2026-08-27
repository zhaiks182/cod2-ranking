@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route($routeName, ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>{{ $icon }}</span> {{ $title }}
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">{{ $subtitle }}</p>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'specialty-season-dropdown',
            'seasonBaseRoute' => $routeName,
            'seasonBaseParams' => ['server' => $server?->slug],
        ])
    </div>

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ $emptyText }}
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Jugador</th>
                        <th class="px-4 py-2 font-medium">Bando</th>
                        <th class="px-4 py-2 font-medium text-right">{{ $valueLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-cyan-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">{!! \App\Support\Cod2Colors::toHtml($row->name) !!}</td>
                            <td class="px-4 py-2 text-slate-400">{{ ucfirst($row->side) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-300 font-medium">{{ $row->c }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
