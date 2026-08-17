<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CoD2 Stats')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        panel: '#111827',
                        panel2: '#0b1220',
                    },
                },
            },
        };
    </script>
    <style>
        body { background: #0b1220; }
        ::-webkit-scrollbar { height: 8px; width: 8px; }
        ::-webkit-scrollbar-thumb { background: #1f2937; border-radius: 4px; }
    </style>
</head>
<body class="bg-panel2 text-slate-200 min-h-screen">
    <header class="border-b border-slate-800 bg-panel/60">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <img src="{{ asset('logo_cod2.webp') }}" alt="Call of Duty 2" class="h-8 w-auto">
            </a>
            <nav class="flex gap-4 text-sm items-center">
                <a href="{{ route('dashboard') }}" class="text-slate-300 hover:text-cyan-400">Inicio</a>
                <a href="{{ route('leaderboard') }}" class="text-slate-300 hover:text-cyan-400">Ranking</a>
                <a href="{{ route('matches.index') }}" class="text-slate-300 hover:text-cyan-400">Partidas</a>
                <div class="relative">
                    <button type="button" data-specialties-toggle onclick="document.getElementById('specialties-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-cyan-400 flex items-center gap-1">
                        Especialidades
                        <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="specialties-dropdown" class="hidden absolute right-0 mt-2 w-64 max-h-[80vh] overflow-y-auto rounded-lg border border-slate-800 bg-panel shadow-xl py-1 z-50">
                        @php
                            $specialtyGroups = [
                                'Combate' => [
                                    'icon' => '⚔️',
                                    'items' => [
                                        ['route' => 'specialties.headshots', 'icon' => '🎯', 'label' => 'Headshots'],
                                        ['route' => 'specialties.grenades', 'icon' => '💣', 'label' => 'Granadas'],
                                        ['route' => 'specialties.weapons', 'icon' => '🔫', 'label' => 'Ranking por arma'],
                                        ['route' => 'specialties.efficiency', 'icon' => '⚔️', 'label' => 'Eficiencia (K/D)'],
                                        ['route' => 'specialties.bombs', 'icon' => '💣', 'label' => 'Especialistas en bombas'],
                                        ['route' => 'specialties.damage', 'icon' => '💥', 'label' => 'Especialistas en daño'],
                                        ['route' => 'specialties.clutches', 'icon' => '🥶', 'label' => 'Clutches 1vX'],
                                        ['route' => 'specialties.streaks-kills', 'icon' => '🔥', 'label' => 'Rachas de bajas'],
                                        ['route' => 'specialties.bash', 'icon' => '🥊', 'label' => 'Bash'],
                                    ],
                                ],
                                'Mapas y partidas' => [
                                    'icon' => '🗺️',
                                    'items' => [
                                        ['route' => 'specialties.maps-won', 'icon' => '🏆', 'label' => 'Mapas ganados'],
                                        ['route' => 'specialties.map-kings', 'icon' => '👑', 'label' => 'Reyes de cada mapa'],
                                        ['route' => 'specialties.streaks', 'icon' => '🔥', 'label' => 'Racha de mapas'],
                                        ['route' => 'specialties.win-rate', 'icon' => '📈', 'label' => 'Win rate por mapa'],
                                    ],
                                ],
                                'Salón de la vergüenza' => [
                                    'icon' => '🙈',
                                    'items' => [
                                        ['route' => 'specialties.grenade-deaths', 'icon' => '🪦', 'label' => 'Muertes por nade'],
                                        ['route' => 'specialties.friendly-fire', 'icon' => '💀', 'label' => 'Fuego amigo'],
                                        ['route' => 'specialties.suicides', 'icon' => '🤡', 'label' => 'Suicidios'],
                                        ['route' => 'specialties.disconnects', 'icon' => '🔌', 'label' => 'Se fueron a media ronda'],
                                    ],
                                ],
                                'Social' => [
                                    'icon' => '💬',
                                    'items' => [
                                        ['route' => 'specialties.chattiest', 'icon' => '💬', 'label' => 'Más hablador'],
                                        ['route' => 'specialties.timeouts', 'icon' => '⏸️', 'label' => 'Timeouts'],
                                    ],
                                ],
                                'Actividad' => [
                                    'icon' => '📊',
                                    'items' => [
                                        ['route' => 'specialties.playtime', 'icon' => '⏱️', 'label' => 'Más horas jugadas'],
                                        ['route' => 'specialties.recent-activity', 'icon' => '📈', 'label' => 'Actividad reciente'],
                                        ['route' => 'specialties.peak-times', 'icon' => '📈', 'label' => 'Hora pico'],
                                        ['route' => 'specialties.countries', 'icon' => '🌎', 'label' => 'Países'],
                                    ],
                                ],
                            ];
                            // Rivalidades: oculto a pedido, la pagina sigue viva en /rivalidades
                        @endphp
                        @foreach($specialtyGroups as $groupName => $group)
                            <div class="{{ !$loop->first ? 'border-t border-slate-800' : '' }}">
                                <button type="button" onclick="toggleSpecGroup(this)"
                                    class="w-full flex items-center justify-between px-3 py-2 text-xs uppercase tracking-wide font-semibold text-slate-400 hover:bg-slate-800/60 hover:text-cyan-400">
                                    <span>{{ $group['icon'] }} {{ $groupName }}</span>
                                    <svg class="w-3 h-3 shrink-0 transition-transform" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                </button>
                                <div class="hidden">
                                    @foreach($group['items'] as $item)
                                        <a href="{{ route($item['route']) }}" class="block pl-6 pr-3 py-2 text-sm text-slate-300 hover:bg-slate-800/60 hover:text-cyan-400">{{ $item['icon'] }} {{ $item['label'] }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">
        @yield('content')
    </main>

    <footer class="max-w-6xl mx-auto px-4 py-3 text-center text-xs text-slate-600 space-y-1">
        <p>Copyright © 2026 zhaiks. All Rights Reserved.</p>
    </footer>

    <div id="teamkill-popover" class="hidden fixed z-50 w-64 max-h-72 overflow-y-auto rounded-lg border border-slate-700 bg-panel shadow-xl text-xs p-3"></div>
    <script>
        function toggleSpecGroup(btn) {
            const submenu = btn.nextElementSibling;
            submenu.classList.toggle('hidden');
            btn.querySelector('svg').classList.toggle('rotate-180');
        }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        async function openDetailsPopover(btn, kind) {
            const popover = document.getElementById('teamkill-popover');
            const guid = btn.dataset.player;
            const params = btn.dataset.params || '';
            const key = kind + ':' + guid + '?' + params;

            if (!popover.classList.contains('hidden') && popover.dataset.owner === key) {
                popover.classList.add('hidden');
                return;
            }

            // The popover is `position: fixed`, so it's already positioned relative to
            // the viewport — getBoundingClientRect() is too, so no scrollX/scrollY
            // offset belongs here. Adding it (previous version) double-counted scroll
            // and pushed the box further down the page the more the user had scrolled.
            const rect = btn.getBoundingClientRect();
            const popoverMaxHeight = 288; // matches max-h-72
            const spaceBelow = window.innerHeight - rect.bottom;
            const openUpward = spaceBelow < popoverMaxHeight && rect.top > spaceBelow;

            popover.style.maxHeight = popoverMaxHeight + 'px';
            if (openUpward) {
                popover.style.top = 'auto';
                popover.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
            } else {
                popover.style.bottom = 'auto';
                popover.style.top = (rect.bottom + 6) + 'px';
            }
            popover.style.left = Math.max(8, Math.min(rect.left - 220, document.documentElement.clientWidth - 272)) + 'px';
            popover.innerHTML = '<div class="text-slate-500">Cargando...</div>';
            popover.classList.remove('hidden');
            popover.dataset.owner = key;

            try {
                const url = kind === 'teamkill' ? `/teamkills/${guid}` : `/kills/${guid}`;
                const res = await fetch(`${url}?${params}`);
                const data = await res.json();

                // A slower, earlier fetch can land after the user already opened a
                // different popover — don't let it clobber what's on screen now.
                if (popover.dataset.owner !== key) return;

                if (!data.length) {
                    popover.innerHTML = '<div class="text-slate-500">Sin detalles disponibles.</div>';
                    return;
                }

                const title = kind === 'teamkill'
                    ? `Fuego amigo (${data.length})`
                    : `Bajas (${data.reduce((s, k) => s + (k.count || 1), 0)})`;
                const titleColor = kind === 'teamkill' ? 'text-red-400' : 'text-cyan-400';

                const rows = data.map(k => `
                    <li class="flex items-center justify-between gap-3 py-1 border-b border-slate-800/60 last:border-0">
                        <span class="text-slate-300 truncate">${escapeHtml(k.victim)}</span>
                        <span class="text-slate-500 shrink-0 text-right">${
                            kind === 'teamkill' ? escapeHtml(k.weapon) : `<span class="text-cyan-400">+${k.count}</span>`
                        }</span>
                    </li>
                `).join('');

                popover.innerHTML = `
                    <div class="${titleColor} font-semibold mb-1.5 uppercase tracking-wide text-[10px]">${title}</div>
                    <ul>${rows}</ul>
                `;
            } catch (e) {
                popover.innerHTML = '<div class="text-red-400">Error al cargar.</div>';
            }
        }

        document.addEventListener('click', (e) => {
            const popover = document.getElementById('teamkill-popover');
            const teamkillTrigger = e.target.closest('[data-teamkill-trigger]');
            const killsTrigger = e.target.closest('[data-kills-trigger]');

            if (teamkillTrigger) {
                openDetailsPopover(teamkillTrigger, 'teamkill');
                return;
            }
            if (killsTrigger) {
                openDetailsPopover(killsTrigger, 'kills');
                return;
            }
            // Other pages (e.g. rivalidades) populate the same shared popover directly
            // via their own onclick handler instead of the fetch-based flow above —
            // don't let this listener immediately hide what that handler just opened.
            if (e.target.closest('[data-rivalry-trigger]') || e.target.closest('[data-weapon-trigger]')) {
                return;
            }
            if (!popover.contains(e.target)) {
                popover.classList.add('hidden');
            }

            const dropdown = document.getElementById('specialties-dropdown');
            if (dropdown && !dropdown.contains(e.target) && !e.target.closest('[data-specialties-toggle]')) {
                dropdown.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
