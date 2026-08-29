<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Fast DL') }} · Zhaiks</title>
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
<body class="bg-panel2 text-slate-200 min-h-screen font-sans flex items-start justify-center px-4 py-10 sm:py-16">
    <div class="w-full max-w-4xl">
        <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <div class="p-6 sm:p-8 text-center border-b border-slate-800">
                <h1 class="font-display text-xl sm:text-2xl">
                    <span class="text-gsaccent">Pug Latam</span> <span class="text-white">Fast Download</span>
                </h1>
                <p class="text-sm text-slate-400 mt-1">{{ __('Explorá y descargá los mods y mapas del servidor.') }}</p>
            </div>

            <div class="px-4 sm:px-6 py-3 border-b border-slate-800 text-xs text-slate-500 font-mono break-all">
                <a href="{{ route('downloads.browse') }}" class="text-gsaccent hover:underline">/cod2</a>
                @foreach($breadcrumbs as $crumb)
                    /
                    @if(!$loop->last)
                        <a href="{{ route('downloads.browse', ['path' => $crumb['path']]) }}" class="text-gsaccent hover:underline">{{ $crumb['name'] }}</a>
                    @else
                        <span class="text-slate-400">{{ $crumb['name'] }}</span>
                    @endif
                @endforeach
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <th class="px-4 sm:px-6 py-2 font-medium">{{ __('Nombre') }}</th>
                        <th class="px-4 py-2 font-medium text-right hidden sm:table-cell">{{ __('Modificado') }}</th>
                        <th class="px-4 sm:px-6 py-2 font-medium text-right">{{ __('Tamaño') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @if($parentPath !== null)
                        <tr class="border-b border-slate-800/60">
                            <td class="px-4 sm:px-6 py-2" colspan="3">
                                <a href="{{ route('downloads.browse', ['path' => $parentPath]) }}" class="inline-flex items-center gap-2 text-slate-300 hover:text-gsaccent">
                                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                    ../
                                </a>
                            </td>
                        </tr>
                    @endif

                    @forelse($entries as $entry)
                        <tr class="border-b border-slate-800/60 last:border-0">
                            <td class="px-4 sm:px-6 py-2">
                                @if($entry['is_dir'])
                                    <a href="{{ route('downloads.browse', ['path' => $entry['path']]) }}" class="inline-flex items-center gap-2 text-gsaccent hover:underline">
                                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h5l2 2h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg>
                                        {{ $entry['name'] }}/
                                    </a>
                                @else
                                    <a href="{{ $publicBaseUrl.'/'.collect(explode('/', $entry['path']))->map(fn ($s) => rawurlencode($s))->implode('/') }}" class="inline-flex items-center gap-2 text-slate-300 hover:text-gsaccent break-all">
                                        <svg class="w-4 h-4 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        {{ $entry['name'] }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-500 hidden sm:table-cell">{{ $entry['modified_human'] }}</td>
                            <td class="px-4 sm:px-6 py-2 text-right tabular-nums text-slate-400">{{ $entry['size_human'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 sm:px-6 py-6 text-center text-slate-500">{{ __('Esta carpeta está vacía.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="px-4 sm:px-6 py-4 border-t border-slate-800 text-center text-xs text-slate-500">
                Copyright &copy; {{ date('Y') }} zhaiks. All Rights Reserved.
            </div>
        </div>

        <p class="text-center text-xs text-slate-600 mt-4">
            <a href="{{ route('downloads') }}" class="hover:text-gsaccent">&larr; {{ __('Volver a Descargas') }}</a>
        </p>
    </div>
</body>
</html>
