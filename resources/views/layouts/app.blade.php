<!DOCTYPE html>
<html lang="es" class="dark scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CoD2 Stats')</title>
    {{-- Open Graph / Twitter Card (2026-08-31) -- para que un link del sitio
    compartido en Discord (donde vive la comunidad, ver settings.discord_*) se
    vea con titulo/descripcion/imagen en vez de una URL pelada. Defaults acá,
    cualquier pagina puede pisarlos con @section('og_title'|'og_description'|'og_image'). --}}
    @php
        $ogTitle = trim($__env->yieldContent('og_title')) ?: trim($__env->yieldContent('title', 'CoD2 Stats — Pug Latam'));
        $ogDescription = trim($__env->yieldContent('og_description')) ?: __('Rankings, estadísticas y partidas del servidor de Call of Duty 2 Pug Latam.');
        $ogImage = trim($__env->yieldContent('og_image')) ?: asset('logo_cod2.webp');
    @endphp
    <meta property="og:site_name" content="CoD2 Stats — Pug Latam">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
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
                        title="{{ __('Tu servidor temporal está activo') }}">
                        <span class="sr-only">{{ __('Tu servidor temporal está activo — ver') }}</span>
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>
                        <span class="absolute top-0.5 right-0.5 flex h-2 w-2" aria-hidden="true">
                            <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                        </span>
                    </a>
                @endif
                {{-- Mini-temporizador flotante de reclamo pendiente (2026-09-01, a pedido
                del dueño) -- mismo lugar/estilo que el icono de "servidor temporal activo"
                de arriba, para no perder de vista cuanto tiempo queda mientras se navega
                a otra pagina del sitio (no solo desde /mi-cuenta). --}}
                @auth('site')
                    @if(auth('site')->user()->hasPendingClaim())
                        <a href="{{ route('account.show') }}"
                            class="flex items-center gap-1 p-1.5 rounded-lg text-cyan-400 hover:text-cyan-300 transition-colors normal-case tracking-normal"
                            title="{{ __('Reclamo de perfil pendiente') }}"
                            data-claim-expires="{{ auth('site')->user()->claim_code_expires_at->toIso8601String() }}">
                            <svg class="w-4 h-4 shrink-0 motion-safe:animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span id="global-claim-countdown" class="text-xs font-mono"></span>
                        </a>
                        <script>
                            (function () {
                                var el = document.currentScript.previousElementSibling;
                                var expiresAt = new Date(el.dataset.claimExpires).getTime();
                                var label = document.getElementById('global-claim-countdown');
                                function tick() {
                                    var remaining = Math.max(0, expiresAt - Date.now());
                                    var mins = Math.floor(remaining / 60000);
                                    var secs = Math.floor((remaining % 60000) / 1000);
                                    label.textContent = mins + ':' + String(secs).padStart(2, '0');
                                    if (remaining <= 0) clearInterval(timer);
                                }
                                var timer = setInterval(tick, 1000);
                                tick();
                            })();
                        </script>
                    @endif
                @endauth
                <a href="{{ route('dashboard') }}" class="text-slate-300 hover:text-gsaccent transition-colors">{{ __('Inicio') }}</a>
                <a href="{{ route('hosted-servers.create') }}" class="text-slate-300 hover:text-gsaccent transition-colors">{{ __('Crear servidor') }}</a>
                <div class="relative">
                    <button type="button" data-ranking-toggle onclick="document.getElementById('ranking-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-gsaccent transition-colors flex items-center gap-1">
                        LEADERBOARDS
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="ranking-dropdown" class="hidden absolute right-0 mt-2 w-48 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        <a href="{{ route('leaderboard') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">🏆 {{ __('Estadísticas') }}</a>
                        <a href="{{ route('matches.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">🎮 {{ __('Partidas') }}</a>
                        <a href="{{ route('rango') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">🎖️ {{ __('Rangos') }}</a>
                        <a href="{{ route('demos.index') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">🎬 Demos</a>
                    </div>
                </div>
                <div class="relative">
                    <button type="button" data-specialties-toggle onclick="document.getElementById('specialties-dropdown').classList.toggle('hidden')"
                        class="text-slate-300 hover:text-gsaccent transition-colors flex items-center gap-1">
                        {{ __('ESPECIALISTA') }}
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="specialties-dropdown" class="hidden absolute right-0 mt-2 w-64 max-w-[calc(100vw-2rem)] max-h-[80vh] overflow-y-auto bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        @php
                            $specialtyGroups = [
                                __('Combate') => [
                                    'icon' => '⚔️',
                                    'items' => [
                                        ['route' => 'specialties.headshots', 'icon' => '🎯', 'label' => __('Headshots')],
                                        ['route' => 'specialties.grenades', 'icon' => '💣', 'label' => __('Granadas')],
                                        ['route' => 'specialties.rivalries', 'icon' => '😈', 'label' => __('Rivalidades')],
                                        ['route' => 'specialties.weapons', 'icon' => '🔫', 'label' => __('Ranking por arma')],
                                        ['route' => 'specialties.efficiency', 'icon' => '⚔️', 'label' => __('Eficiencia (K/D)')],
                                        ['route' => 'specialties.bombs', 'icon' => '💣', 'label' => __('Especialistas en bombas')],
                                        ['route' => 'specialties.damage', 'icon' => '💥', 'label' => __('Especialistas en daño')],
                                        ['route' => 'specialties.clutches', 'icon' => '🥶', 'label' => __('Clutches 1vX')],
                                        ['route' => 'specialties.streaks-kills', 'icon' => '🔥', 'label' => __('Rachas de bajas')],
                                        ['route' => 'specialties.bash', 'icon' => '🥊', 'label' => __('Bash')],
                                    ],
                                ],
                                __('Mapas y partidas') => [
                                    'icon' => '🗺️',
                                    'items' => [
                                        ['route' => 'specialties.maps-won', 'icon' => '🏆', 'label' => __('Mapas ganados')],
                                        ['route' => 'specialties.map-kings', 'icon' => '👑', 'label' => __('Reyes de cada mapa')],
                                        ['route' => 'specialties.streaks', 'icon' => '🔥', 'label' => __('Racha de mapas')],
                                        ['route' => 'specialties.win-rate', 'icon' => '📈', 'label' => __('Win rate general')],
                                    ],
                                ],
                                __('Salón de la vergüenza') => [
                                    'icon' => '🙈',
                                    'items' => [
                                        ['route' => 'specialties.grenade-deaths', 'icon' => '🪦', 'label' => __('Muertes por nade')],
                                        ['route' => 'specialties.friendly-fire', 'icon' => '💀', 'label' => __('Fuego amigo')],
                                        ['route' => 'specialties.suicides', 'icon' => '🤡', 'label' => __('Suicidios')],
                                        ['route' => 'specialties.disconnects', 'icon' => '🔌', 'label' => __('Se fueron a media ronda')],
                                    ],
                                ],
                                __('Social') => [
                                    'icon' => '💬',
                                    'items' => [
                                        ['route' => 'specialties.chattiest', 'icon' => '💬', 'label' => __('Más hablador')],
                                        ['route' => 'specialties.timeouts', 'icon' => '⏸️', 'label' => 'Timeouts'],
                                    ],
                                ],
                                __('Actividad') => [
                                    'icon' => '📊',
                                    'items' => [
                                        ['route' => 'specialties.playtime', 'icon' => '⏱️', 'label' => __('Más horas jugadas')],
                                        ['route' => 'specialties.recent-activity', 'icon' => '📈', 'label' => __('Actividad reciente')],
                                        ['route' => 'specialties.peak-times', 'icon' => '📈', 'label' => __('Hora pico')],
                                        ['route' => 'specialties.countries', 'icon' => '🌎', 'label' => __('Países')],
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
                        {{ __('AYUDA') }}
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="help-dropdown" class="hidden absolute right-0 mt-2 w-56 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        <a href="{{ route('faq') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">❓ {{ __('Preguntas frecuentes') }}</a>
                        <a href="{{ route('downloads') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">⬇️ {{ __('Descargas') }}</a>
                    </div>
                </div>
                @if (config('services.discord.client_id'))
                    @auth('site')
                        <div class="relative">
                            <button type="button" data-account-toggle onclick="document.getElementById('account-dropdown').classList.toggle('hidden')"
                                class="flex items-center gap-1.5 text-slate-300 hover:text-gsaccent transition-colors normal-case tracking-normal">
                                @if(auth('site')->user()->discord_avatar_url)
                                    <img src="{{ auth('site')->user()->discord_avatar_url }}" alt="" class="w-5 h-5 rounded-full">
                                @endif
                                {{ auth('site')->user()->discord_username }}
                            </button>
                            <div id="account-dropdown" class="hidden absolute right-0 mt-2 w-44 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                                <a href="{{ route('account.show') }}" class="block px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">{{ __('Mi cuenta') }}</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-gsprimary/20 hover:text-gsaccent">{{ __('Cerrar sesión') }}</button>
                                </form>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="text-slate-300 hover:text-gsaccent transition-colors normal-case tracking-normal">{{ __('Iniciar sesión') }}</a>
                    @endauth
                @endif
                {{-- Selector ES/EN (2026-08-29, a pedido del dueño, referencia
                hostgamer.net/es) -- solo icono de bandera + chevron en el trigger,
                banderas via GeoIp::flagIconHtml() (flagcdn.com, reusado del mismo
                helper que ya usan las tablas de jugadores -- ver "GeoIp y banderas
                de país" en CLAUDE.md, nunca emoji de bandera por el problema de
                render en Windows ya documentado ahí). Al final del nav (2026-09-01,
                a pedido del dueño). --}}
                <div class="relative">
                    <button type="button" data-lang-toggle onclick="document.getElementById('lang-dropdown').classList.toggle('hidden')"
                        class="flex items-center gap-1 p-1 text-slate-300 hover:text-gsaccent transition-colors normal-case tracking-normal"
                        aria-label="Cambiar idioma / Change language">
                        {!! \App\Services\GeoIp::flagIconHtml(app()->getLocale() === 'en' ? 'us' : 'es', 20, 14) !!}
                        <svg class="w-3 h-3 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div id="lang-dropdown" class="hidden absolute right-0 mt-2 w-36 max-w-[calc(100vw-2rem)] bg-panel shadow-xl py-1 z-50 normal-case tracking-normal font-normal">
                        <a href="{{ route('locale.switch', 'es') }}" class="flex items-center gap-2 px-3 py-2 text-sm {{ app()->getLocale() === 'es' ? 'text-gsaccent' : 'text-slate-300' }} hover:bg-gsprimary/20 hover:text-gsaccent">
                            {!! \App\Services\GeoIp::flagIconHtml('es', 18, 13) !!} Español
                        </a>
                        <a href="{{ route('locale.switch', 'en') }}" class="flex items-center gap-2 px-3 py-2 text-sm {{ app()->getLocale() === 'en' ? 'text-gsaccent' : 'text-slate-300' }} hover:bg-gsprimary/20 hover:text-gsaccent">
                            {!! \App\Services\GeoIp::flagIconHtml('us', 18, 13) !!} English
                        </a>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">
        {{-- Banner global de status/error (2026-09-01) -- antes solo /mi-cuenta
        mostraba esto, asi que un flash desde otra pagina (ej. login de Discord
        fallido, que redirige a /) se perdia en silencio. --}}
        @if(session('status'))
            <div class="mb-4 rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300 text-sm px-4 py-2">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-lg border border-red-800 bg-red-950/40 text-red-300 text-sm px-4 py-2">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>

    <footer class="max-w-6xl mx-auto px-4 py-3 text-center text-xs uppercase tracking-widest text-slate-600">
        <p>Copyright © 2026 zhaiks. All Rights Reserved.</p>
    </footer>

    <div id="teamkill-popover" class="hidden fixed z-50 w-64 max-h-72 overflow-y-auto bg-panel shadow-xl text-xs p-3"></div>
    <script>
        // Textos usados por el JS de mas abajo (cod2CopyConnect, openDetailsPopover,
        // cod2MeasurePing) -- __() no corre en el navegador, asi que se resuelven
        // server-side una sola vez aca y el JS solo lee de este objeto.
        window.cod2I18n = {
            copied: @json(__('Copiado')),
            error: @json(__('Error')),
            noLatencyData: @json(__('sin datos de latencia')),
            loading: @json(__('Cargando...')),
            noDetails: @json(__('Sin detalles disponibles.')),
            errorLoading: @json(__('Error al cargar.')),
            friendlyFire: @json(__('Fuego amigo')),
            grenadeKills: @json(__('Bajas con granada')),
            deaths: @json(__('Muertes')),
            kills: @json(__('Bajas')),
            youKilledThem: @json(__('Lo mataste')),
            theyKilledYou: @json(__('Te mató')),
            searchNoResults: @json(__('Sin resultados.')),
            searchHint: @json(__('Escribí al menos 2 letras...')),
            kills: @json(__('bajas')),
        };

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
                    ? '<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 scale-0 animate-[cod2-pop_0.3s_ease-out_forwards]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.79-6.796a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>' + window.cod2I18n.copied + '</span>'
                    : '<span class="inline-flex items-center gap-1">' + window.cod2I18n.error + '</span>';
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
                el.textContent = window.cod2I18n.noLatencyData;
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
            popover.innerHTML = '<div class="text-slate-500">' + window.cod2I18n.loading + '</div>';
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
                    popover.innerHTML = '<div class="text-slate-500">' + window.cod2I18n.noDetails + '</div>';
                    return;
                }

                const title = kind === 'teamkill' ? `${window.cod2I18n.friendlyFire} (${data.length})`
                    : kind === 'headshots' ? `Headshots (${data.reduce((s, k) => s + (k.count || 1), 0)})`
                    : kind === 'grenades' ? `${window.cod2I18n.grenadeKills} (${data.reduce((s, k) => s + (k.count || 1), 0)})`
                    : kind === 'deaths' ? `${window.cod2I18n.deaths} (${data.reduce((s, k) => s + (k.count || 1), 0)})`
                    : `${window.cod2I18n.kills} (${data.reduce((s, k) => s + (k.count || 1), 0)})`;
                const titleColor = kind === 'teamkill' || kind === 'deaths' ? 'text-red-400' : 'text-cyan-400';
                const countColor = kind === 'deaths' ? 'text-red-400' : 'text-cyan-400';
                const reverseLabel = kind === 'deaths' ? window.cod2I18n.youKilledThem : window.cod2I18n.theyKilledYou;
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
                popover.innerHTML = '<div class="text-red-400">' + window.cod2I18n.errorLoading + '</div>';
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

            const langDropdown = document.getElementById('lang-dropdown');
            if (langDropdown && !langDropdown.contains(e.target) && !e.target.closest('[data-lang-toggle]')) {
                langDropdown.classList.add('hidden');
            }

            const accountDropdown = document.getElementById('account-dropdown');
            if (accountDropdown && !accountDropdown.contains(e.target) && !e.target.closest('[data-account-toggle]')) {
                accountDropdown.classList.add('hidden');
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
