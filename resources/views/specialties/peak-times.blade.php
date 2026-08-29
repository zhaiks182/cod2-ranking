@extends('layouts.app')

@section('title', __('Hora Pico'))

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.peak-times', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold flex items-center gap-2">
                <span>📈</span> {{ __('Hora Pico') }}
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">{{ __('Cuándo hay más actividad — bajas de Search and Destroy por hora y día') }}</p>
        </div>

        @include('partials.season-selector', [
            'seasonDropdownId' => 'specialty-season-dropdown',
            'seasonBaseRoute' => 'specialties.peak-times',
            'seasonBaseParams' => ['server' => $server?->slug],
        ])
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4">
        <h2 class="text-xs uppercase tracking-wide text-slate-400 mb-3">{{ __('Por hora del día') }}</h2>
        <div class="space-y-1">
            @foreach($byHour as $row)
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-12 text-slate-500 tabular-nums">{{ $row->label }}</span>
                    <div class="flex-1 bg-panel2 rounded h-3 overflow-hidden">
                        <div class="h-full bg-cyan-600" style="width: {{ $row->value > 0 ? max(2, round($row->value / $maxHour * 100)) : 0 }}%"></div>
                    </div>
                    <span class="w-10 text-right text-slate-400 tabular-nums">{{ $row->value }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel p-4">
        <h2 class="text-xs uppercase tracking-wide text-slate-400 mb-3">{{ __('Por día de la semana') }}</h2>
        <div class="space-y-1">
            @foreach($byWeekday as $row)
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-12 text-slate-500">{{ $row->label }}</span>
                    <div class="flex-1 bg-panel2 rounded h-3 overflow-hidden">
                        <div class="h-full bg-fuchsia-600" style="width: {{ $row->value > 0 ? max(2, round($row->value / $maxWeekday * 100)) : 0 }}%"></div>
                    </div>
                    <span class="w-10 text-right text-slate-400 tabular-nums">{{ $row->value }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
