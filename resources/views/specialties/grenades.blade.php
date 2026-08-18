@extends('layouts.app')

@section('title', 'Especialistas en Granadas')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.grenades', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            <span>💣</span> Especialistas en Granadas
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">Ranking de bajas con granada — Search and Destroy, {{ $server?->name ?? 'servidor' }}</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Bajas con granada</div>
            <div class="mt-1 text-lg font-semibold text-amber-400">{{ $totalGrenadeKills }}</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">% del total de bajas</div>
            <div class="mt-1 text-lg font-semibold">{{ $totalKills > 0 ? round($totalGrenadeKills / $totalKills * 100, 1) : 0 }}%</div>
        </div>
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3 col-span-2 md:col-span-1">
            <div class="text-[11px] uppercase tracking-wide text-slate-500">Granada favorita</div>
            <div class="mt-1 text-lg font-semibold">{{ $favoriteGrenade ? \App\Support\WeaponCatalog::label($favoriteGrenade->weapon) : '—' }}</div>
        </div>
    </div>

    @if($rows->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay bajas con granada registradas.
        </div>
    @else
        @php $podium = $rows->take(3); $rest = $rows->slice(3)->values(); @endphp

        {{-- Podium --}}
        <div class="grid grid-cols-3 gap-3 items-end">
            {{-- 2nd place --}}
            <div class="order-1">
                @if($podium->count() > 1)
                    @php $p = $podium[1]; @endphp
                    <div class="rounded-xl border border-slate-700 bg-panel px-3 py-4 text-center">
                        <div class="text-2xl">🥈</div>
                        <a href="{{ route('players.show', $p->player->guid) }}" class="mt-1 block font-medium text-sm truncate hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->player->last_name) !!}</a>
                        <div class="mt-1 text-xl font-bold text-amber-400">{{ $p->grenade_kills }}</div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide">granadas</div>
                    </div>
                @endif
            </div>

            {{-- 1st place --}}
            <div class="order-2">
                @if($podium->count() > 0)
                    @php $p = $podium[0]; @endphp
                    <div class="rounded-xl border-2 border-amber-500 bg-gradient-to-b from-amber-950/40 to-panel px-3 py-6 text-center shadow-lg shadow-amber-950/50">
                        <div class="text-3xl">🥇</div>
                        <a href="{{ route('players.show', $p->player->guid) }}" class="mt-1 block font-semibold truncate hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->player->last_name) !!}</a>
                        <div class="mt-1 text-3xl font-bold text-amber-300">{{ $p->grenade_kills }}</div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide">granadas</div>
                        <div class="mt-1 text-[11px] text-slate-400">{{ $p->grenade_share }}% de sus bajas</div>
                    </div>
                @endif
            </div>

            {{-- 3rd place --}}
            <div class="order-3">
                @if($podium->count() > 2)
                    @php $p = $podium[2]; @endphp
                    <div class="rounded-xl border border-slate-700 bg-panel px-3 py-3 text-center">
                        <div class="text-xl">🥉</div>
                        <a href="{{ route('players.show', $p->player->guid) }}" class="mt-1 block font-medium text-sm truncate hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($p->player->last_name) !!}</a>
                        <div class="mt-1 text-lg font-bold text-amber-400">{{ $p->grenade_kills }}</div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide">granadas</div>
                    </div>
                @endif
            </div>
        </div>

        @if($rest->isNotEmpty())
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                            <th class="px-4 py-2 font-medium">#</th>
                            <th class="px-4 py-2 font-medium">Jugador</th>
                            <th class="px-4 py-2 font-medium text-right">Granadas</th>
                            <th class="px-4 py-2 font-medium text-right">% de sus bajas</th>
                            <th class="px-4 py-2 font-medium text-right">Kills totales</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rest as $i => $row)
                            <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                                <td class="px-4 py-2 text-slate-500">{{ $i + 4 }}</td>
                                <td class="px-4 py-2 font-medium">
                                    <a href="{{ route('players.show', $row->player->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($row->player->last_name) !!}</a>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-amber-400 font-medium">{{ $row->grenade_kills }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ $row->grenade_share }}%</td>
                                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">{{ $row->kills }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
