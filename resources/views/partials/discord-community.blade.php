{{--
    Solo markup/datos aca -- el script de auto-refresh vive en dashboard.blade.php,
    NO en este partial. Este partial se vuelve a renderizar SOLO (via
    DashboardController::discordWidget(), route dashboard.discord-widget) en cada
    poll del JS -- si el script estuviera aca adentro, se re-inyectaria y
    duplicaria (setInterval apilandose) en cada refresh. Mismo problema real que
    ya se corrigio en el widget de Recursos del panel admin (commit a372352);
    el widget de "Servidor en vivo" de esta misma pagina SI tiene ese bug
    (ver tarea aparte), pero este widget nuevo no lo repite.
--}}
@if($discord)
<div id="discord-widget" data-refresh-url="{{ route('dashboard.discord-widget') }}" data-online="{{ $discord['online'] }}">
    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="max-h-72 overflow-y-auto divide-y divide-slate-800/50">
            @forelse(array_slice($discord['members'], 0, 20) as $member)
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <div class="relative shrink-0">
                        @if($member['avatar_url'] ?? null)
                            <img src="{{ $member['avatar_url'] }}" alt="" class="w-8 h-8 rounded-full bg-slate-800">
                        @else
                            <div class="w-8 h-8 rounded-full bg-slate-700"></div>
                        @endif
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-panel {{ match($member['status'] ?? '') {
                            'online' => 'bg-emerald-400',
                            'idle' => 'bg-amber-400',
                            'dnd' => 'bg-red-500',
                            default => 'bg-slate-600',
                        } }}"></span>
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate">{{ $member['username'] ?? '?' }}</div>
                        <div class="text-[11px] text-slate-500 truncate">
                            {{ $member['game']['name'] ?? match($member['status'] ?? '') {
                                'online' => 'En línea',
                                'idle' => 'Ausente',
                                'dnd' => 'No molestar',
                                default => '',
                            } }}
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm text-slate-600">Nadie conectado en Discord ahora mismo.</div>
            @endforelse
        </div>
        <div class="px-4 py-3 border-t border-slate-800 flex items-center justify-between gap-3">
            <span class="text-xs text-slate-500">Chateá con la comunidad y enterate de novedades del server.</span>
            @if(config('services.discord.invite_url'))
                <a href="{{ config('services.discord.invite_url') }}" target="_blank" rel="noopener" class="shrink-0 px-3 py-1.5 rounded-lg bg-[#5865F2] hover:bg-[#4752c4] text-white text-xs font-medium transition-colors">Unirme a Discord</a>
            @endif
        </div>
    </div>
</div>
@endif
