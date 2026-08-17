@extends('layouts.app')

@section('title', 'Ranking por Arma')

@section('content')
<div class="space-y-6">
    @if($servers->count() > 1)
        <div class="flex items-center gap-2 text-sm">
            @foreach($servers as $s)
                <a href="{{ route('specialties.weapons', ['server' => $s->slug]) }}" class="px-3 py-1.5 rounded-lg border {{ $server?->id === $s->id ? 'border-cyan-500 text-cyan-400' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">{{ $s->name }}</a>
            @endforeach
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold flex items-center gap-2">
            <span>🔫</span> Ranking por Arma
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">Las armas más letales del servidor, y quién es el más mortal con cada una</p>
    </div>

    @if($weapons->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay datos suficientes.
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Arma</th>
                        <th class="px-4 py-2 font-medium text-right">Bajas totales</th>
                        <th class="px-4 py-2 font-medium">Más letal con esta arma</th>
                        <th class="px-4 py-2 font-medium text-right">Bajas con ella</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($weapons as $i => $w)
                        @php $country = $w->topPlayer ? \App\Services\GeoIp::countryFor($w->topPlayer->ip) : null; @endphp
                        <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                            <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium">{{ \App\Support\WeaponCatalog::label($w->weapon) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-cyan-300 font-medium">
                                <button type="button"
                                    data-weapon-trigger
                                    data-weapon-label="{{ \App\Support\WeaponCatalog::label($w->weapon) }}"
                                    data-killers="{{ $w->allKillers->toJson() }}"
                                    onclick="showWeaponKillers(this)"
                                    class="hover:underline hover:text-cyan-200">{{ $w->uses }}</button>
                            </td>
                            <td class="px-4 py-2">
                                @if($w->topPlayer)
                                    @if($country)<span class="mr-1" title="{{ $country['name'] }}">{!! \App\Services\GeoIp::flagIconHtml($country['code']) !!}</span>@endif
                                    <a href="{{ route('players.show', $w->topPlayer->guid) }}" class="hover:text-cyan-400">{!! \App\Support\Cod2Colors::toHtml($w->topPlayer->last_name) !!}</a>
                                @else
                                    <span class="text-slate-600">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-amber-400">{{ $w->topUses ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>

<script>
    function showWeaponKillers(btn) {
        const popover = document.getElementById('teamkill-popover');
        const key = 'weapon:' + btn.dataset.weaponLabel;

        if (!popover.classList.contains('hidden') && popover.dataset.owner === key) {
            popover.classList.add('hidden');
            return;
        }

        const rect = btn.getBoundingClientRect();
        popover.style.maxHeight = '288px';
        popover.style.bottom = 'auto';
        popover.style.top = (rect.bottom + 6) + 'px';
        popover.style.left = Math.max(8, Math.min(rect.left - 200, document.documentElement.clientWidth - 272)) + 'px';
        popover.dataset.owner = key;

        const killers = JSON.parse(btn.dataset.killers || '[]');
        const rows = killers.map(k => `
            <li class="flex items-center justify-between gap-3 py-1 border-b border-slate-800/60 last:border-0">
                <a href="/jugadores/${k.guid}" class="text-slate-300 hover:text-cyan-400 truncate">${escapeHtml(k.name)}</a>
                <span class="text-cyan-400 font-medium shrink-0">${escapeHtml(String(k.uses))}</span>
            </li>
        `).join('');

        popover.innerHTML = `
            <div class="text-cyan-400 font-semibold mb-1.5 uppercase tracking-wide text-[10px]">Bajas con ${escapeHtml(btn.dataset.weaponLabel)}</div>
            <ul>${rows || '<li class="text-slate-500 py-1">Sin datos.</li>'}</ul>
        `;
        popover.classList.remove('hidden');
    }
</script>
@endsection
