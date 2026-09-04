@extends('layouts.app')

@section('title', $clan->name)

@section('content')
<div class="space-y-6">
    @if(session('status'))
        <div class="rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300 text-sm px-4 py-2">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-800 bg-red-950/40 text-red-300 text-sm px-4 py-2 space-y-1">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    {{-- Header --}}
    <div class="flex items-start gap-4 flex-wrap">
        @if($clan->logo_url)
            <img src="{{ $clan->logo_url }}" alt="" class="w-20 h-20 rounded-xl object-cover border border-slate-700 shrink-0">
        @else
            <div class="w-20 h-20 rounded-xl bg-panel2 border border-slate-700 flex items-center justify-center text-3xl shrink-0">🛡️</div>
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-semibold">{{ $clan->name }}</h1>
            @if($clan->description)<p class="text-sm text-slate-400 mt-1">{{ $clan->description }}</p>@endif
            <p class="text-xs text-slate-500 mt-1">
                {{ __('Fundador') }}: <span class="text-slate-300">{{ $clan->founder->player->last_name_plain ?? $clan->founder->discord_username }}</span>
                · {{ __('Fundado') }} {{ $clan->founded_on->format('d/m/Y') }}
                · {{ __(':n miembro(s)', ['n' => $clan->members->count()]) }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @auth('site')
                @if(! $myMembership && ! $myPendingRequest)
                    <form method="POST" action="{{ route('clans.request', $clan) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-gsprimary hover:bg-gsprimary/80 text-white text-sm font-semibold">{{ __('Solicitar unirse') }}</button>
                    </form>
                @elseif($myPendingRequest)
                    <span class="px-3 py-1.5 rounded-lg border border-slate-700 text-slate-400 text-sm">{{ __('Solicitud enviada') }}</span>
                @endif
                @if($myMembership && $myMembership->clan_id === $clan->id)
                    <form method="POST" action="{{ route('clans.leave', $clan) }}" onsubmit="return confirm('{{ __('¿Seguro que querés salir del clan?') }}');">
                        @csrf @method('DELETE')
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:border-red-500 hover:text-red-400 text-sm font-semibold">{{ __('Salir') }}</button>
                    </form>
                @endif
                @if($isFounder)
                    <form method="POST" action="{{ route('clans.disband', $clan) }}" onsubmit="return confirm('{{ __('Esto borra el clan para siempre, junto con todos sus miembros. ¿Confirmás?') }}') && confirm('{{ __('No se puede deshacer. ¿Disolver de todas formas?') }}');">
                        @csrf @method('DELETE')
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-900 hover:bg-red-800 text-white text-sm font-semibold">{{ __('Disolver') }}</button>
                    </form>
                @endif
            @endauth
        </div>
    </div>

    {{-- Estadisticas --}}
    <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Estadísticas combinadas de los miembros') }}</h2>
            @include('partials.season-selector', [
                'seasonDropdownId' => 'clan-season-dropdown',
                'seasonBaseRoute' => 'clans.show',
                'seasonBaseParams' => ['clan' => $clan->tag],
            ])
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
            <div><div class="text-lg font-semibold text-slate-200">{{ $stats->kills }}</div><div class="text-[11px] text-slate-500">{{ __('Kills') }}</div></div>
            <div><div class="text-lg font-semibold text-slate-200">{{ $stats->deaths }}</div><div class="text-[11px] text-slate-500">{{ __('Muertes') }}</div></div>
            <div><div class="text-lg font-semibold text-cyan-300">{{ $stats->kd }}</div><div class="text-[11px] text-slate-500">K/D</div></div>
            <div><div class="text-lg font-semibold text-slate-200">{{ $stats->matches }}</div><div class="text-[11px] text-slate-500">{{ __('Partidas') }}</div></div>
        </div>
    </div>

    {{-- Miembros --}}
    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold px-4 py-3 border-b border-slate-800">{{ __('Miembros') }}</h2>
        <div class="divide-y divide-slate-800/60">
            @foreach($clan->members as $member)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="flex items-center gap-2 min-w-0">
                        @if($member->siteUser->player)
                            <a href="{{ route('players.show', $member->siteUser->player) }}" class="text-slate-200 hover:text-cyan-400 truncate">{!! \App\Support\Cod2Colors::toHtml($member->siteUser->player->last_name) !!}</a>
                        @else
                            <span class="text-slate-400 truncate">{{ $member->siteUser->discord_username }}</span>
                        @endif
                        <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded
                            {{ $member->role === 'founder' ? 'bg-amber-950/40 text-amber-300 border border-amber-700' : ($member->role === 'manager' ? 'bg-cyan-950/40 text-cyan-300 border border-cyan-700' : 'bg-slate-800 text-slate-400') }}">
                            {{ $member->role === 'founder' ? __('Fundador') : ($member->role === 'manager' ? __('Manager') : __('Miembro')) }}
                        </span>
                    </div>

                    @if($canManage && $member->site_user_id !== $siteUser?->id)
                        <div class="flex items-center gap-2 shrink-0">
                            @if($isFounder && ! $member->isFounder())
                                <form method="POST" action="{{ route('clans.members.role', [$clan, $member]) }}">
                                    @csrf
                                    <input type="hidden" name="role" value="{{ $member->isManager() ? 'member' : 'manager' }}">
                                    <button type="submit" class="text-xs text-slate-500 hover:text-cyan-400">{{ $member->isManager() ? __('Degradar') : __('Ascender') }}</button>
                                </form>
                                <form method="POST" action="{{ route('clans.transfer', $clan) }}" onsubmit="return confirm('{{ __('¿Transferir la fundación a este miembro? Vos pasás a ser Miembro.') }}');">
                                    @csrf
                                    <input type="hidden" name="member_id" value="{{ $member->id }}">
                                    <button type="submit" class="text-xs text-slate-500 hover:text-amber-400">{{ __('Transferir fundación') }}</button>
                                </form>
                            @endif
                            @if(! $member->isFounder() && ($isFounder || $member->role === 'member'))
                                <form method="POST" action="{{ route('clans.members.kick', [$clan, $member]) }}" onsubmit="return confirm('{{ __('¿Expulsar a este miembro?') }}');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-slate-500 hover:text-red-400">{{ __('Expulsar') }}</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @if($canManage)
        {{-- Invitar --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-2">
            <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Invitar jugador') }}</h2>
            <div class="relative max-w-sm">
                <input type="text" id="clan-invite-search" placeholder="{{ __('Buscar jugador por nombre...') }}" autocomplete="off"
                    class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                <div id="clan-invite-results" class="hidden absolute left-0 right-0 mt-1 bg-panel border border-slate-800 rounded-lg shadow-xl z-20 max-h-56 overflow-y-auto"></div>
            </div>
            <form id="clan-invite-form" method="POST" action="{{ route('clans.invite', $clan) }}">
                @csrf
                <input type="hidden" name="site_user_id" id="clan-invite-site-user-id">
            </form>
        </div>

        @if($pendingRequests->isNotEmpty())
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-2">
                <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Solicitudes pendientes') }}</h2>
                @foreach($pendingRequests as $req)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-slate-300">{{ $req->siteUser->player->last_name_plain ?? $req->siteUser->discord_username }}</span>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('clans.requests.respond', [$clan, $req]) }}">
                                @csrf<input type="hidden" name="accept" value="1">
                                <button type="submit" class="px-3 py-1 rounded-lg bg-emerald-700 hover:bg-emerald-600 text-white text-xs font-semibold">{{ __('Aprobar') }}</button>
                            </form>
                            <form method="POST" action="{{ route('clans.requests.respond', [$clan, $req]) }}">
                                @csrf<input type="hidden" name="accept" value="0">
                                <button type="submit" class="px-3 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold">{{ __('Rechazar') }}</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($pendingSentInvites->isNotEmpty())
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-2">
                <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Invitaciones enviadas, pendientes de respuesta') }}</h2>
                @foreach($pendingSentInvites as $inv)
                    <p class="text-sm text-slate-400">{{ $inv->siteUser->player->last_name_plain ?? $inv->siteUser->discord_username }}</p>
                @endforeach
            </div>
        @endif

        {{-- Editar clan --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4">
            <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold mb-3">{{ __('Editar clan') }}</h2>
            <form method="POST" action="{{ route('clans.update', $clan) }}" enctype="multipart/form-data" class="max-w-xl space-y-3">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-[1fr_140px] gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Nombre') }}</label>
                        <input type="text" name="name" value="{{ old('name', $clan->name) }}" maxlength="60" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Tag') }}</label>
                        <input type="text" name="tag" value="{{ old('tag', $clan->tag) }}" maxlength="15" required pattern="[A-Za-z0-9_-]+" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">{{ __('Descripción') }}</label>
                    <textarea name="description" maxlength="1000" rows="2" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">{{ old('description', $clan->description) }}</textarea>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Fecha de fundación') }}</label>
                        <input type="date" name="founded_on" value="{{ old('founded_on', $clan->founded_on->toDateString()) }}" max="{{ now()->toDateString() }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 [color-scheme:dark]">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Logo') }}</label>
                        <input type="file" name="logo" accept="image/png,image/jpeg" class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-gsprimary file:text-white file:text-xs file:font-semibold hover:file:bg-gsprimary/80">
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-gsprimary hover:bg-gsprimary/80 text-white text-sm font-semibold">{{ __('Guardar cambios') }}</button>
            </form>
        </div>
    @endif
</div>

@if($canManage)
<script>
    (function () {
        const input = document.getElementById('clan-invite-search');
        const results = document.getElementById('clan-invite-results');
        const hiddenId = document.getElementById('clan-invite-site-user-id');
        const form = document.getElementById('clan-invite-form');
        let timer = null;

        // Con q vacío el endpoint devuelve el listado completo de usuarios
        // ya registrados elegibles (2026-09-04, a pedido del dueño) -- se
        // pide al enfocar el campo, no solo al escribir, para que el
        // manager pueda tildar directo a alguien sin tener que tipear nada.
        function loadResults(q) {
            fetch('{{ route('clans.search-invitable', $clan) }}?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(players => {
                    if (!players.length) { results.innerHTML = '<div class="px-3 py-2 text-xs text-slate-500">{{ __('Sin resultados') }}</div>'; results.classList.remove('hidden'); return; }
                    results.innerHTML = players.map(p =>
                        `<button type="button" data-id="${p.id}" class="clan-invite-option block w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">${p.name}</button>`
                    ).join('');
                    results.classList.remove('hidden');
                    results.querySelectorAll('.clan-invite-option').forEach(btn => {
                        btn.addEventListener('click', () => {
                            hiddenId.value = btn.dataset.id;
                            form.submit();
                        });
                    });
                });
        }

        input.addEventListener('focus', () => loadResults(input.value.trim()));

        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => loadResults(input.value.trim()), 300);
        });

        document.addEventListener('click', (e) => {
            if (!results.contains(e.target) && e.target !== input) results.classList.add('hidden');
        });
    })();
</script>
@endif
@endsection
