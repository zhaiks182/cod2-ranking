@extends('layouts.app')

@section('title', 'Rivalidades')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.rivalries', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            <span>😈</span> Rivalidades
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">El "verdugo" de cada jugador — quién lo mató más veces que nadie (mínimo 3 bajas contra esa víctima). Click en una fila para ver el cara a cara completo.</p>
    </div>

    @if($rivalries->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay suficientes enfrentamientos repetidos para armar este ranking.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Víctima</th>
                        <th class="px-4 py-2 font-medium">Verdugo</th>
                        <th class="px-4 py-2 font-medium text-right">Veces</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rivalries as $i => $r)
                        @php
                            $victimCountry = \App\Services\GeoIp::countryFor($r->victim->ip);
                            $nemesisCountry = \App\Services\GeoIp::countryFor($r->nemesis->ip);
                        @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30 cursor-pointer"
                            data-rivalry-trigger
                            data-nemesis="{{ $r->nemesis->last_name_plain }}"
                            data-victim="{{ $r->victim->last_name_plain }}"
                            data-count="{{ $r->count }}"
                            data-reverse="{{ $r->reverseCount }}"
                            data-weapon="{{ $r->weapon ? \App\Support\WeaponCatalog::label($r->weapon) : '' }}"
                            onclick="showRivalryDetail(this)">
                            <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-2">
                                @if($victimCountry)<span class="mr-1" title="{{ $victimCountry['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($victimCountry['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $r->victim->guid) }}" onclick="event.stopPropagation()" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($r->victim->last_name) !!}</a>
                            </td>
                            <td class="px-4 py-2 font-medium">
                                @if($nemesisCountry)<span class="mr-1" title="{{ $nemesisCountry['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($nemesisCountry['code']) !!}</span>@endif
                                <a href="{{ route('players.show', $r->nemesis->guid) }}" onclick="event.stopPropagation()" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($r->nemesis->last_name) !!}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-red-400 font-medium">{{ $r->count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>

<script>
    function showRivalryDetail(row) {
        const popover = document.getElementById('teamkill-popover');
        const key = 'rivalry:' + row.dataset.nemesis + ':' + row.dataset.victim;

        if (!popover.classList.contains('hidden') && popover.dataset.owner === key) {
            popover.classList.add('hidden');
            return;
        }

        const rect = row.getBoundingClientRect();
        popover.style.maxHeight = '288px';
        popover.style.bottom = 'auto';
        popover.style.top = (rect.bottom + 6) + 'px';
        popover.style.left = Math.max(8, Math.min(rect.left, document.documentElement.clientWidth - 272)) + 'px';
        popover.dataset.owner = key;

        const weaponRow = row.dataset.weapon ? `
                <li class="flex items-center justify-between gap-3 py-1 border-t border-slate-800/60 mt-1 pt-1.5">
                    <span class="text-slate-500">Arma favorita</span>
                    <span class="text-slate-300 font-medium shrink-0">${escapeHtml(row.dataset.weapon)}</span>
                </li>` : '';

        popover.innerHTML = `
            <div class="text-red-400 font-semibold mb-1.5 uppercase tracking-wide text-[10px]">Cara a cara</div>
            <ul>
                <li class="flex items-center justify-between gap-3 py-1 border-b border-slate-800/60">
                    <span class="text-slate-300 truncate">${escapeHtml(row.dataset.nemesis)} → ${escapeHtml(row.dataset.victim)}</span>
                    <span class="text-red-400 font-medium shrink-0">${escapeHtml(row.dataset.count)}</span>
                </li>
                <li class="flex items-center justify-between gap-3 py-1">
                    <span class="text-slate-300 truncate">${escapeHtml(row.dataset.victim)} → ${escapeHtml(row.dataset.nemesis)}</span>
                    <span class="text-slate-400 font-medium shrink-0">${escapeHtml(row.dataset.reverse)}</span>
                </li>
                ${weaponRow}
            </ul>
        `;
        popover.classList.remove('hidden');
    }
</script>
@endsection
