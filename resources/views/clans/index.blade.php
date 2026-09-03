@extends('layouts.app')

@section('title', __('Clanes'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-lg font-semibold flex items-center gap-2"><span>🛡️</span> {{ __('Clanes') }}</h1>
        @auth('site')
            <a href="{{ route('clans.create') }}" class="px-3 py-1.5 rounded-lg bg-gsprimary hover:bg-gsprimary/80 text-white text-sm font-semibold">{{ __('+ Crear clan') }}</a>
        @endauth
    </div>

    <form method="GET" class="flex gap-2">
        <input type="text" name="q" value="{{ $q }}" placeholder="{{ __('Buscar por nombre o tag...') }}" class="flex-1 bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
        <button type="submit" class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-500 hover:text-cyan-400 text-sm">{{ __('Buscar') }}</button>
    </form>

    @forelse($clans as $clan)
        <a href="{{ route('clans.show', $clan) }}" class="flex items-center gap-4 rounded-xl border border-slate-800 bg-panel px-4 py-3 hover:bg-slate-800/30">
            @if($clan->logo_url)
                <img src="{{ $clan->logo_url }}" alt="" class="w-12 h-12 rounded-lg object-cover shrink-0 border border-slate-700">
            @else
                <div class="w-12 h-12 rounded-lg bg-panel2 border border-slate-700 flex items-center justify-center text-slate-600 shrink-0">🛡️</div>
            @endif
            <div class="min-w-0">
                <div class="font-medium text-slate-200">{{ $clan->name }}</div>
                <div class="text-xs text-slate-500">{{ __(':n miembro(s) · fundado :date', ['n' => $clan->members_count, 'date' => $clan->founded_on->format('d/m/Y')]) }}</div>
            </div>
        </a>
    @empty
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ $q !== '' ? __('Ningún clan coincide con la búsqueda.') : __('Todavía no hay ningún clan creado.') }}
        </div>
    @endforelse

    {{ $clans->links() }}
</div>
@endsection
