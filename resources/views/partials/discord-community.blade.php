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
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3">
            <span class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('Miembros conectados') }}</span>
            @if($discord['name'] ?? null)
                <span class="text-sm font-semibold text-white">{{ $discord['name'] }}</span>
            @endif
        </div>

        <div class="max-h-72 overflow-y-auto space-y-2 p-3">
            @forelse(array_slice($discord['members'], 0, 20) as $member)
                <div class="flex items-center gap-3 rounded-lg border border-slate-800/80 bg-slate-900/40 px-3 py-2.5">
                    <div class="relative shrink-0">
                        @if($member['avatar_url'] ?? null)
                            <img src="{{ $member['avatar_url'] }}" alt="" class="w-9 h-9 rounded-full bg-slate-800">
                        @else
                            <div class="w-9 h-9 rounded-full bg-slate-700"></div>
                        @endif
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-panel {{ match($member['status'] ?? '') {
                            'online' => 'bg-emerald-400',
                            'idle' => 'bg-amber-400',
                            'dnd' => 'bg-red-500',
                            default => 'bg-slate-600',
                        } }}"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium truncate">{{ $member['username'] ?? '?' }}</div>
                        <div class="text-[11px] text-slate-500 truncate">
                            {{ $member['game']['name'] ?? match($member['status'] ?? '') {
                                'online' => __('En línea'),
                                'idle' => __('Ausente'),
                                'dnd' => __('No molestar'),
                                default => '',
                            } }}
                        </div>
                    </div>
                    <span class="shrink-0 text-[10px] uppercase tracking-wide font-medium px-2 py-0.5 rounded-full {{ match($member['status'] ?? '') {
                        'online' => 'bg-emerald-950/50 text-emerald-400 border border-emerald-900',
                        'idle' => 'bg-amber-950/50 text-amber-400 border border-amber-900',
                        'dnd' => 'bg-red-950/50 text-red-400 border border-red-900',
                        default => 'bg-slate-800 text-slate-400 border border-slate-700',
                    } }}">
                        {{ match($member['status'] ?? '') {
                            'online' => 'Online',
                            'idle' => __('Ausente'),
                            'dnd' => __('Ocupado'),
                            default => '—',
                        } }}
                    </span>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm text-slate-600">{{ __('Nadie conectado en Discord ahora mismo.') }}</div>
            @endforelse
        </div>

        <div class="px-4 py-3 border-t border-slate-800">
            <div class="rounded-lg border border-slate-800 bg-slate-950/40 px-4 py-3 flex items-center justify-between">
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-slate-500">{{ __('Miembros online') }}</div>
                    <div class="text-2xl font-semibold tabular-nums text-indigo-400">{{ $discord['online'] }}</div>
                </div>
                <svg class="w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
        </div>

        <div class="px-4 py-3 border-t border-slate-800 flex items-center justify-between gap-3">
            <span class="text-xs text-slate-500">{{ __('Unite para chatear y pedir soporte') }}</span>
            @if($discordSetting->discord_invite_url ?? null)
                <a href="{{ $discordSetting->discord_invite_url }}" target="_blank" rel="noopener" class="shrink-0 px-3 py-1.5 rounded-lg bg-[#5865F2] hover:bg-[#4752c4] text-white text-xs font-medium transition-colors">{{ __('Unirme a Discord') }}</a>
            @endif
        </div>
    </div>
</div>
@endif
