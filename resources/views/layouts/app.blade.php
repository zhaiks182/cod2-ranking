<!DOCTYPE html>
<html lang="es" class="dark scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CoD2 Stats')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Tema "Void terminal glass" (2026-08-28, ver testing frontend/neon-db-design.md,
        // fuente: neon.com) -- se remapean slate/cyan en vez de tocar clase por clase en
        // las ~49 plantillas: casi todo el sitio ya usaba esos dos tokens para
        // fondo/texto/bordes (slate) y acento (cyan), asi que redefinir la escala acá
        // cascadea el look nuevo sin editar cada vista.
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        panel: '#111315',
                        panel2: '#000000',
                        gsprimary: '#47D18C',
                        gsaccent: '#34D59A',
                        slate: {
                            50: '#FFFFFF',
                            100: '#F5F6F7',
                            200: '#FFFFFF',
                            300: '#C9CBCF',
                            400: '#94979E',
                            500: '#75787F',
                            600: '#4B4D52',
                            700: '#303236',
                            800: '#18191B',
                            900: '#111315',
                            950: '#000000',
                        },
                        cyan: {
                            50: '#EAFBF3',
                            100: '#CFF5E3',
                            200: '#A6ECCB',
                            300: '#6EDFAE',
                            400: '#34D59A',
                            500: '#20C28A',
                            600: '#17A374',
                            700: '#128364',
                            800: '#106951',
                            900: '#0E5643',
                            950: '#062E23',
                        },
                    },
                    fontFamily: {
                        display: ['"Inter"', 'sans-serif'],
                        sans: ['"Inter"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    borderRadius: {
                        none: '0px',
                        sm: '0px',
                        DEFAULT: '0px',
                        md: '0px',
                        lg: '0px',
                        xl: '0px',
                        '2xl': '0px',
                        '3xl': '0px',
                        full: '9999px',
                    },
                    letterSpacing: {
                        tightest: '-0.06em',
                    },
                },
            },
        };
    </script>
    <style>
        body { background: #000000; }
        ::-webkit-scrollbar { height: 8px; width: 8px; }
        ::-webkit-scrollbar-thumb { background: #303236; }

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
            </div>
            <nav class="flex flex-wrap justify-end gap-x-3 sm:gap-x-6 gap-y-1 text-xs sm:text-sm uppercase tracking-[0.06em] sm:tracking-[0.1em] font-semibold items-center min-w-0">
                @if ($activeHostedServer ?? null)
                    {{-- Sin esto, alguien que crea un server temporal y navega a otra
                    pagina del sitio no tiene forma de volver -- no hay login, la URL
                    con el token es lo unico que administra ese server (ver
                    AppServiceProvider::boot()). A la izquierda de "Inicio" a pedido
                    del dueño (2026-08-24) -- antes vivia al lado del logo, despues a
                    la derecha de "Inicio" (dos ajustes de posicion el mismo dia). --}}
                    <a href="{{ route('hosted-servers.show', [$activeHostedServer, $activeHostedServer->management_token]) }}"
                        class="relative p-1.5 rounded-lg text-slate-300 hover:text-gsaccent transition-colors normal-case tracking-normal"
                        title="Tu servidor temporal está activo">
                        <span class="sr-only">Tu servidor temporal está activo — ver</span>
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>
                        <span class="absolute top-0.5 right-0.5 flex h-2 w-2" aria-hidden="true">
                            <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                        </span>
                    </a>
                @endif
                <a href="{{ route('dashboard') }}" class="text-slate-300 hover:text-gsaccent transition-colors">Inicio</a>
                <a href="{{ route('hosted-servers.create') }}" class="text-slate-300 hover:text-gsaccent transition-colors">Crear servidor</a>
                <div class="relative">
                    <button type="button" data-ranking-toggle onclick="document.getElementById('ranking-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-gsaccent transition-colors flex items-center gap-1">
                        LEADERBOARDS
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="ranking-dropdown" class="hidden absolute right-0 mt-2 w-48 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        <a href="{{ route('leaderboard') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Estadísticas</a>
                        <a href="{{ route('matches.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Partidas</a>
                        <a href="{{ route('rango') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">Rangos</a>
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
                                        ['route' => 'specialties.rivalries', 'icon' => '😈', 'label' => 'Rivalidades'],
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
                                        ['route' => 'specialties.win-rate', 'icon' => '📈', 'label' => 'Win rate general'],
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

        // Latencia real hacia el VPS -- /ping (public/ping, archivo estatico, ver
        // CLAUDE.md) en direct.cod2.4livepro.com, subdominio DNS-only sin el proxy
        // de Cloudflare de por medio (sumaba ~35ms de peaje). 3 muestras, se
        // descarta la primera (warmup de la conexion TLS) y se promedian las
        // ultimas 2. Compartida entre dashboard.blade.php y
        // hosted-servers/create.blade.php -- antes vivia duplicada en cada vista.
        window.cod2MeasurePing = async function (elId) {
            var el = document.getElementById(elId);
            if (!el) return;

            async function ping() {
                var start = performance.now();
                try {
                    await fetch('https://direct.cod2.4livepro.com/ping', { cache: 'no-store', mode: 'cors' });
                    return performance.now() - start;
                } catch (e) {
                    return null;
                }
            }

            var samples = [];
            for (var i = 0; i < 3; i++) {
                var ms = await ping();
                if (ms !== null) samples.push(ms);
            }

            if (samples.length < 2) {
                el.textContent = 'sin datos de latencia';
                return;
            }

            samples.shift();
            var avg = Math.round(samples.reduce(function (a, b) { return a + b; }, 0) / samples.length);
            var color = avg < 80 ? 'text-emerald-400' : (avg < 150 ? 'text-amber-400' : 'text-red-400');
            el.className = color + ' font-medium';
            el.textContent = '~' + avg + 'ms';
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
                // headshots/grenades (2026-08-28, a pedido de un jugador -- "debe ser
                // igual que las kills") reusan el mismo endpoint /kills/{guid} que
                // 'kills', solo con &type=headshot|grenade para acotar el popover a
                // ese subconjunto -- no es un endpoint nuevo, ver KillDetailController.
                // 'deaths' (2026-08-29, misma logica: la card "Muertes" no tenia detalle
                // al hacer click) manda &direction=deaths -- mismo endpoint, atacante/
                // victima invertidos server-side.
                const typeParam = kind === 'headshots' ? '&type=headshot' : kind === 'grenades' ? '&type=grenade' : '';
                const directionParam = kind === 'deaths' ? '&direction=deaths' : '';
                const url = kind === 'teamkill' ? `/teamkills/${guid}` : `/kills/${guid}`;
                const res = await fetch(`${url}?${params}${typeParam}${directionParam}`);
                const data = await res.json();

                // A slower, earlier fetch can land after the user already opened a
                // different popover — don't let it clobber what's on screen now.
                if (popover.dataset.owner !== key) return;

                if (!data.length) {
                    popover.innerHTML = '<div class="text-slate-500">Sin detalles disponibles.</div>';
                    return;
                }

                const title = kind === 'teamkill' ? `Fuego amigo (${data.length})`
                    : kind === 'headshots' ? `Headshots (${data.reduce((s, k) => s + (k.count || 1), 0)})`
                    : kind === 'grenades' ? `Bajas con granada (${data.reduce((s, k) => s + (k.count || 1), 0)})`
                    : kind === 'deaths' ? `Muertes (${data.reduce((s, k) => s + (k.count || 1), 0)})`
                    : `Bajas (${data.reduce((s, k) => s + (k.count || 1), 0)})`;
                const titleColor = kind === 'teamkill' || kind === 'deaths' ? 'text-red-400' : 'text-cyan-400';
                const countColor = kind === 'deaths' ? 'text-red-400' : 'text-cyan-400';
                const reverseLabel = kind === 'deaths' ? 'Lo mataste' : 'Te mató';
                const reverseColor = kind === 'deaths' ? 'text-cyan-400' : 'text-red-400';

                // En "kills"/"headshots"/"grenades"/"deaths" (no en teamkill), cada fila
                // con jugador real (k.reverse != null -- los bots no tienen player_id,
                // asi que no hay "cara a cara" posible con ellos) se puede clickear para
                // revelar el mismo enfrentamiento en la direccion contraria, sin pedir
                // nada al server de nuevo -- ya vino en la misma respuesta (ver
                // KillDetailController).
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
                            <span class="${countColor} shrink-0 text-right">+${k.count}</span>
                        </div>
                        ${clickable ? `
                        <div data-reverse-row class="hidden flex items-center justify-between gap-3 pb-1.5 -mt-0.5">
                            <span class="text-slate-500 text-[11px]">${reverseLabel}</span>
                            <span class="${reverseColor} shrink-0 text-[11px] font-medium">${k.reverse}</span>
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
            const headshotsTrigger = e.target.closest('[data-headshots-trigger]');
            if (headshotsTrigger) {
                openDetailsPopover(headshotsTrigger, 'headshots');
                return;
            }
            const grenadesTrigger = e.target.closest('[data-grenades-trigger]');
            if (grenadesTrigger) {
                openDetailsPopover(grenadesTrigger, 'grenades');
                return;
            }
            const deathsTrigger = e.target.closest('[data-deaths-trigger]');
            if (deathsTrigger) {
                openDetailsPopover(deathsTrigger, 'deaths');
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

        // ESC cierra cualquier popup abierto (2026-08-29, a pedido del dueño) --
        // hasta ahora solo se podia cerrar clickeando afuera o el boton "X". Global
        // porque este layout es la base de toda pagina publica y los modales de cada
        // una (perfil de jugador, partida, ranking, etc.) siguen la misma convencion
        // de id "*-modal" -- un solo listener cubre todos sin tener que repetirlo en
        // cada vista. `[id*="-modal"]` (contiene, no "termina en") porque algunos ids
        // llevan un sufijo dinamico (ej. "dates-modal-2026-08" en leaderboard.blade.php).
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;

            document.getElementById('teamkill-popover')?.classList.add('hidden');
            document.querySelectorAll('[id*="-modal"]').forEach((m) => m.classList.add('hidden'));
        });
    </script>
</body>
</html>
