<!DOCTYPE html>
<html lang="es" class="dark scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CoD2 Stats')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&family=Russo+One&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        panel: '#111827',
                        panel2: '#0b1220',
                        gsprimary: '#1e40af',
                        gsaccent: '#38bdf8',
                    },
                    fontFamily: {
                        display: ['"Russo One"', 'sans-serif'],
                        sans: ['"Chakra Petch"', 'sans-serif'],
                    },
                },
            },
        };
    </script>
    <style>
        body { background: #0b1220; }
        ::-webkit-scrollbar { height: 8px; width: 8px; }
        ::-webkit-scrollbar-thumb { background: #1f2937; }

        /* Usado por cod2CopyConnect() mas abajo -- compartido entre cualquier boton
        de "copiar" de la pagina (Servidor en vivo, FAQ, etc.). */
        @keyframes cod2-pop {
            0% { transform: scale(0); opacity: 0; }
            60% { transform: scale(1.3); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body class="bg-panel2 text-slate-200 min-h-screen font-sans">
    <header class="bg-panel">
        <div class="max-w-6xl mx-auto px-4 py-5 flex items-center justify-between gap-3">
            <div class="flex items-center gap-1 shrink-0">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                    <span class="font-display text-lg tracking-wide text-slate-200">CoD2 <span class="text-gsaccent">STATS</span></span>
                </a>
                @if ($activeHostedServer ?? null)
                    {{-- Sin esto, alguien que crea un server temporal y navega a otra
                    pagina del sitio no tiene forma de volver -- no hay login, la URL
                    con el token es lo unico que administra ese server (ver
                    AppServiceProvider::boot()). --}}
                    <a href="{{ route('hosted-servers.show', [$activeHostedServer, $activeHostedServer->management_token]) }}"
                        class="relative p-1.5 rounded-lg text-slate-300 hover:text-gsaccent transition-colors"
                        title="Tu servidor temporal está activo">
                        <span class="sr-only">Tu servidor temporal está activo — ver</span>
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>
                        <span class="absolute top-0.5 right-0.5 flex h-2 w-2" aria-hidden="true">
                            <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                        </span>
                    </a>
                @endif
            </div>
            <nav class="flex flex-wrap justify-end gap-x-3 sm:gap-x-6 gap-y-1 text-xs sm:text-sm uppercase tracking-[0.06em] sm:tracking-[0.1em] font-semibold items-center min-w-0">
                <a href="{{ route('dashboard') }}" class="text-slate-300 hover:text-gsaccent transition-colors">Inicio</a>
                <a href="{{ route('hosted-servers.create') }}" class="text-slate-300 hover:text-gsaccent transition-colors">Crear servidor</a>
                <div class="relative">
                    <button type="button" data-ranking-toggle onclick="document.getElementById('ranking-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-gsaccent transition-colors flex items-center gap-1">
                        RANKING
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="ranking-dropdown" class="hidden absolute right-0 mt-2 w-48 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        <a href="{{ route('leaderboard') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Ranking</a>
                        <a href="{{ route('rango') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Rangos</a>
                        <a href="{{ route('matches.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Partidas</a>
                        <a href="{{ route('demos.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Demos</a>
                    </div>
                </div>
                <div class="relative">
                    <button type="button" data-specialties-toggle onclick="document.getElementById('specialties-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-gsaccent transition-colors flex items-center gap-1">
                        ESPECIALISTA
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="specialties-dropdown" class="hidden absolute right-0 mt-2 w-64 max-w-[calc(100vw-2rem)] max-h-[80vh] overflow-y-auto bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
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
                                    class="w-full flex items-center justify-between px-3 py-2 text-xs uppercase tracking-wide font-semibold text-slate-400 hover:bg-gsprimary/20 hover:text-gsaccent">
                                    <span>{{ $group['icon'] }} {{ $groupName }}</span>
                                    <svg class="w-3 h-3 shrink-0 transition-transform" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                </button>
                                <div class="hidden">
                                    @foreach($group['items'] as $item)
                                        <a href="{{ route($item['route']) }}" class="block pl-6 pr-3 py-2 text-sm uppercase tracking-wide text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">{{ $item['icon'] }} {{ $item['label'] }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="relative">
                    <button type="button" data-help-toggle onclick="document.getElementById('help-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-gsaccent transition-colors flex items-center gap-1">
                        AYUDA
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="help-dropdown" class="hidden absolute right-0 mt-2 w-56 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        <a href="{{ route('faq') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">❓ Preguntas frecuentes</a>
                        <a href="{{ route('downloads') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">⬇️ Descargas</a>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">
        @yield('content')
    </main>

    <footer class="max-w-6xl mx-auto px-4 py-3 text-center text-xs uppercase tracking-widest text-slate-600">
        <p>Copyright © 2026 zhaiks. All Rights Reserved.</p>
    </footer>

    <div id="teamkill-popover" class="hidden fixed z-50 w-64 max-h-72 overflow-y-auto bg-panel shadow-xl text-xs p-3"></div>
    <script>
        function toggleSpecGroup(btn) {
            const submenu = btn.nextElementSibling;
            submenu.classList.toggle('hidden');
            btn.querySelector('svg').classList.toggle('rotate-180');
        }

        // Antes vivia dentro de partials/live-status.blade.php (dentro de un bloque
        // @@once), asi que solo existia en paginas que incluyen ese partial. Se sube aca para
        // que cualquier pagina (por ejemplo help/faq.blade.php) pueda reusar el mismo
        // boton de "copiar" con el mismo feedback visual.
        window.cod2CopyConnect = function (btn, text) {
            var originalHtml = btn.innerHTML;
            var successClasses = ['border-emerald-500', 'text-emerald-400', 'scale-105'];
            var errorClasses = ['border-red-500', 'text-red-400'];

            var flash = function (ok) {
                btn.innerHTML = ok
                    ? '<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 scale-0 animate-[cod2-pop_0.3s_ease-out_forwards]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.79-6.796a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>Copiado</span>'
                    : '<span class="inline-flex items-center gap-1">Error</span>';
                btn.classList.add.apply(btn.classList, ok ? successClasses : errorClasses);
                btn.classList.remove('border-slate-700');

                setTimeout(function () {
                    btn.innerHTML = originalHtml;
                    btn.classList.remove.apply(btn.classList, successClasses.concat(errorClasses));
                    btn.classList.add('border-slate-700');
                }, 1500);
            };

            var fallbackCopy = function () {
                var el = document.createElement('textarea');
                el.value = text;
                el.style.position = 'fixed';
                el.style.opacity = '0';
                document.body.appendChild(el);
                el.focus();
                el.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(el);
                flash(ok);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    flash(true);
                }).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        };

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        // Solo una fila de "cara a cara" abierta a la vez dentro del mismo popover --
        // clickear otra victima cierra la anterior en vez de acumular varias abiertas.
        function toggleReverseRow(li) {
            const row = li.querySelector('[data-reverse-row]');
            const wasHidden = row.classList.contains('hidden');

            li.closest('ul').querySelectorAll('[data-reverse-row]').forEach(r => r.classList.add('hidden'));

            if (wasHidden) {
                row.classList.remove('hidden');
            }
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

                // En "kills" (no en teamkill), cada fila con victima real (k.reverse !=
                // null -- los bots no tienen player_id, asi que no hay "cara a cara"
                // posible con ellos) se puede clickear para revelar cuantas veces esa
                // misma victima mato de vuelta al jugador, sin pedir nada al server de
                // nuevo -- ya vino en la misma respuesta (ver KillDetailController).
                const rows = data.map(k => {
                    if (kind === 'teamkill') {
                        return `
                        <li class="flex items-center justify-between gap-3 py-1 border-b border-slate-800/60 last:border-0">
                            <span class="text-slate-300 truncate">${escapeHtml(k.victim)}</span>
                            <span class="text-slate-500 shrink-0 text-right">${escapeHtml(k.weapon)}</span>
                        </li>`;
                    }

                    const clickable = k.reverse !== null;

                    return `
                    <li class="border-b border-slate-800/60 last:border-0 ${clickable ? 'cursor-pointer hover:bg-slate-800/40' : ''}"${
                        clickable ? ` onclick="toggleReverseRow(this)"` : ''
                    }>
                        <div class="flex items-center justify-between gap-3 py-1">
                            <span class="text-slate-300 truncate">${escapeHtml(k.victim)}</span>
                            <span class="text-cyan-400 shrink-0 text-right">+${k.count}</span>
                        </div>
                        ${clickable ? `
                        <div data-reverse-row class="hidden flex items-center justify-between gap-3 pb-1.5 -mt-0.5">
                            <span class="text-slate-500 text-[11px]">Te mató</span>
                            <span class="text-red-400 shrink-0 text-[11px] font-medium">${k.reverse}</span>
                        </div>` : ''}
                    </li>`;
                }).join('');

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

            const rankingDropdown = document.getElementById('ranking-dropdown');
            if (rankingDropdown && !rankingDropdown.contains(e.target) && !e.target.closest('[data-ranking-toggle]')) {
                rankingDropdown.classList.add('hidden');
            }

            const helpDropdown = document.getElementById('help-dropdown');
            if (helpDropdown && !helpDropdown.contains(e.target) && !e.target.closest('[data-help-toggle]')) {
                helpDropdown.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
