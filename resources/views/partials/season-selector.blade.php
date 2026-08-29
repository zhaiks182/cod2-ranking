{{--
    Selector de temporada reusable -- requiere:
    - $seasons: Collection de Season, mas reciente primero
    - $seasonId: int o 'all', la seleccionada actualmente
    - $seasonBaseRoute: nombre de ruta (ej. 'leaderboard', 'players.show')
    - $seasonBaseParams: array de parametros a preservar en el link (sin 'season')
    - $seasonDropdownId: id unico del dropdown en esta pagina (puede haber mas de un
      selector en la misma pagina si se reusa el partial dos veces)
--}}
<div class="relative">
    <button type="button" onclick="document.getElementById('{{ $seasonDropdownId }}').classList.toggle('hidden')"
        class="px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:border-cyan-500 hover:text-cyan-400 text-sm flex items-center gap-1.5">
        @if($seasonId === 'all')
            {{ __('Todo el historial') }}
        @else
            {{ $seasons->firstWhere('id', $seasonId)?->name ?? __('Temporada') }}
        @endif
        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
    </button>
    <div id="{{ $seasonDropdownId }}" class="hidden absolute right-0 mt-2 w-52 bg-panel border border-slate-800 shadow-xl py-1 z-50 rounded-lg text-sm">
        @foreach($seasons as $season)
            <a href="{{ route($seasonBaseRoute, array_merge($seasonBaseParams, ['season' => $season->id])) }}"
                class="block px-3 py-2 {{ $seasonId === $season->id ? 'text-cyan-400' : 'text-slate-300' }} hover:bg-gsprimary/20 hover:text-gsaccent">
                {{ $season->name }}
                @if(! $season->ended_at)<span class="text-[10px] text-emerald-400 ml-1">{{ __('activa') }}</span>@endif
            </a>
        @endforeach
        <a href="{{ route($seasonBaseRoute, array_merge($seasonBaseParams, ['season' => 'all'])) }}"
            class="block px-3 py-2 border-t border-slate-800 mt-1 pt-2 {{ $seasonId === 'all' ? 'text-cyan-400' : 'text-slate-300' }} hover:bg-gsprimary/20 hover:text-gsaccent">
            {{ __('Todo el historial') }}
        </a>
    </div>
</div>
