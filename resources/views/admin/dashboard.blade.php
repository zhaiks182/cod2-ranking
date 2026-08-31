@extends('layouts.admin')

@section('title', 'Panel')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-lg font-semibold">Panel</h1>
        <p class="text-xs text-slate-500 mt-1">Resumen general del sitio y accesos rápidos.</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Jugadores</div>
            <div class="mt-1 text-2xl font-semibold">{{ number_format($stats['players_total']) }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Partidas hoy</div>
            <div class="mt-1 text-2xl font-semibold text-cyan-400">{{ $stats['matches_today'] }}</div>
            <div class="text-xs text-slate-600">{{ number_format($stats['matches_total']) }} en total</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Demos</div>
            <div class="mt-1 text-2xl font-semibold">{{ number_format($stats['demos_total']) }}</div>
            <div class="text-xs text-slate-600">{{ $stats['demos_size_human'] }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Servidores temporales</div>
            <div class="mt-1 text-2xl font-semibold {{ $stats['hosted_servers_active'] > 0 ? 'text-emerald-400' : '' }}">{{ $stats['hosted_servers_active'] }}<span class="text-slate-600 text-base">/{{ $stats['hosted_servers_max'] }}</span></div>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-2">Temporada activa</div>
            <div class="text-base font-medium">{{ $season->name }}</div>
            <div class="text-xs text-slate-500 mt-0.5">Desde {{ $season->started_at->format('d/m/Y') }}</div>
            <a href="{{ route('admin.seasons.index') }}" class="inline-block mt-3 text-xs text-cyan-400 hover:underline">Gestionar temporadas →</a>
        </div>

        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-2">Disco del servidor</div>
            @if($disk['used_percent'] !== null)
                <div class="text-base font-medium {{ $disk['used_percent'] >= 90 ? 'text-red-400' : ($disk['used_percent'] >= 75 ? 'text-amber-400' : '') }}">{{ $disk['used_percent'] }}% usado</div>
                <div class="text-xs text-slate-500 mt-0.5">{{ $disk['free_human'] }} libres</div>
            @else
                <div class="text-sm text-slate-600">No disponible.</div>
            @endif
        </div>

        <div class="rounded-xl border border-slate-800 bg-panel p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-2">Servidores reales</div>
            @forelse($servers as $s)
                <a href="{{ route('admin.console.show', $s) }}" class="flex items-center justify-between text-sm py-0.5 hover:text-cyan-400">
                    <span>{{ $s->name }}</span>
                    <span class="text-slate-600">→</span>
                </a>
            @empty
                <div class="text-sm text-slate-600">Sin servidores configurados.</div>
            @endforelse
        </div>
    </div>

    <div>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm uppercase tracking-wide text-slate-500">Últimas acciones</h2>
            <a href="{{ route('admin.audit.index') }}" class="text-xs text-cyan-400 hover:underline">Ver todo →</a>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
            @forelse($recentActions as $action)
                <div class="px-4 py-2.5 text-sm flex items-start justify-between gap-4">
                    <div>
                        <span class="text-slate-200 font-medium">{{ $action->user?->username ?? __('Sistema') }}</span>
                        <span class="text-slate-500">— {{ $action->description }}</span>
                    </div>
                    <span class="text-xs text-slate-600 whitespace-nowrap">{{ $action->created_at->format('d/m H:i') }}</span>
                </div>
            @empty
                <div class="px-4 py-4 text-center text-sm text-slate-500">Sin acciones registradas todavía.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
