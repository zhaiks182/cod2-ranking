<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') — CoD2 Stats</title>
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
    <style>body { background: #0b1220; }</style>
</head>
<body class="bg-panel2 text-slate-200 min-h-screen font-sans">
    @auth
        <header class="border-b border-slate-800 bg-panel/60">
            <div class="max-w-6xl mx-auto px-4 py-4 flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('admin.servers.index') }}" class="flex items-center gap-2.5 shrink-0">
                    <span class="font-display text-lg tracking-wide text-slate-200">CoD2 <span class="text-gsaccent">STATS</span></span>
                </a>
                <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <a href="{{ route('admin.servers.index') }}" class="text-slate-300 hover:text-gsaccent">Servidores</a>
                    <a href="{{ route('admin.matches.index') }}" class="text-slate-300 hover:text-gsaccent">Partidas</a>
                    <a href="{{ route('admin.maps.index') }}" class="text-slate-300 hover:text-gsaccent">Mapas</a>
                    <a href="{{ route('admin.players.index') }}" class="text-slate-300 hover:text-gsaccent">Países</a>
                    <a href="{{ route('admin.password.edit') }}" class="text-slate-300 hover:text-gsaccent">Contraseña</a>
                    <a href="{{ route('dashboard') }}" class="text-slate-300 hover:text-gsaccent">Ver sitio público</a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="text-slate-400 hover:text-red-400">Cerrar sesión</button>
                    </form>
                </nav>
            </div>
        </header>
    @endauth

    <main class="max-w-6xl mx-auto px-4 py-6">
        @if(session('status'))
            <div class="mb-4 rounded-xl border border-emerald-900 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>
</body>
</html>
