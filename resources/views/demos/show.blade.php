@extends('layouts.app')

@section('title', \App\Support\MapCatalog::mapLabel($match->map).' — Demos')

@section('content')
<div class="space-y-6">
    <div class="flex items-start gap-3">
        @if($mapImageUrl = \App\Support\MapImage::url($match->map))
            <img src="{{ $mapImageUrl }}" alt="" class="h-20 w-20 rounded-lg object-cover shrink-0">
        @endif
        <div>
            <a href="{{ route('demos.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← {{ __('Volver a demos') }}</a>
            <h1 class="text-lg font-semibold mt-1">{{ \App\Support\MapCatalog::mapLabel($match->map) }} · {{ $match->started_at->format('d/m/Y H:i') }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                {{ __(':n demo(s) · total :mb MB', ['n' => $demos->count(), 'mb' => number_format($demos->sum('size_bytes') / 1024 / 1024, 1)]) }}
                @if($match->final_score)
                    · <span class="text-slate-300 font-medium">{{ $match->final_score }}</span>
                @endif
                ·
                <a href="{{ route('matches.show', $match) }}" class="text-cyan-400 hover:text-cyan-300">{{ __('ver estadisticas de la partida') }}</a>
            </p>
        </div>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">{{ __('Jugador') }}</th>
                    <th class="px-4 py-2 font-medium">Demo</th>
                    <th class="px-4 py-2 font-medium">{{ __('Hora') }}</th>
                    <th class="px-4 py-2 font-medium text-right">{{ __('Tamaño') }}</th>
                    <th class="px-4 py-2 font-medium text-right">{{ __('Acciones') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($demos as $demo)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            @if($demo->player)
                                <a href="{{ route('players.show', $demo->player->guid) }}" class="hover:text-gsaccent">{!! \App\Support\Cod2Colors::toHtml($demo->player->last_name) !!}</a>
                                <x-player-icon :player="$demo->player" />
                            @else
                                <span class="text-slate-400">{{ __('Desconocido') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-400">{{ $demo->demo_name }}</td>
                        <td class="px-4 py-2 text-slate-400">{{ $demo->created_at->format('H:i') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ number_format($demo->size_bytes / 1024 / 1024, 1) }} MB</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('demos.download', $demo) }}" class="text-cyan-400 hover:text-cyan-300">{{ __('Descargar') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('No hay demos para esta partida.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
