@extends('layouts.app')

@section('title', 'Preguntas frecuentes')

@section('content')
<style>
    /* <details> nativo trae su propio marcador de disclosure -- list-none lo saca en
    Chrome/Firefox pero WebKit necesita este selector aparte para el mismo efecto. */
    summary::-webkit-details-marker { display: none; }
</style>

<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <h1 class="font-display text-2xl md:text-3xl font-bold text-white">Preguntas frecuentes</h1>
        <p class="text-sm text-slate-400 mt-1">Todo lo que necesitás para conectarte y jugar sin problemas en Pug Latam.</p>
    </div>

    <div class="space-y-3">
        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden" open>
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                ¿Cómo me conecto al servidor?
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>Abrí Multijugador, presioná <kbd class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-xs text-slate-300">~</kbd> para abrir la consola, y ejecutá:</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 min-w-0 px-3 py-2 rounded-lg bg-panel2 border border-slate-800 font-mono text-cyan-300 text-xs truncate">/connect {{ $connectString }}</code>
                    <button type="button" onclick="cod2CopyConnect(this, {{ json_encode('/connect '.$connectString) }})"
                        class="shrink-0 text-xs px-3 py-2 rounded-lg border border-slate-600 text-slate-200 hover:border-gsaccent hover:text-gsaccent transition-all duration-200 ease-out">Copiar</button>
                </div>
                <p>Si la consola está desactivada, activala primero desde las opciones del juego. También podés usar el botón <strong class="text-white">Estado del servidor</strong> arriba de la página de inicio para ver la IP y copiarla con un clic.</p>
                @if($connectUri)
                    <a href="{{ $connectUri }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gsprimary hover:bg-blue-700 text-white text-sm font-semibold transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Conectar
                    </a>
                    <p class="text-slate-600 text-xs">Necesita CoD2x instalado (ver más abajo) para que el navegador sepa abrir <code class="bg-panel2 border border-slate-800 rounded px-1">{{ $connectUri }}</code> con el juego.</p>
                @endif
                <p>Versión recomendada del juego: <strong class="text-white">1.3</strong> con CoD2x instalado.</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                Arreglar caídas de FPS y tirones (ISLC)
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>Si tenés tirones en Windows 10/11, <strong class="text-white">ISLC (Intelligent Standby List Cleaner)</strong> puede ayudar a estabilizar el uso de memoria.</p>
                <ol class="list-decimal list-inside space-y-1.5">
                    <li>Descargalo desde el <a href="https://www.wagnardsoft.com/forums/viewtopic.php?t=1256" target="_blank" rel="noopener" class="text-gsaccent hover:underline">hilo oficial de Wagnardsoft</a>.</li>
                    <li>Ejecutalo como <strong class="text-white">Administrador</strong>.</li>
                    <li>Configurá el umbral de memoria a la mitad de tu RAM.</li>
                    <li>Configurá el polling rate en <strong class="text-white">1000ms</strong>.</li>
                    <li>Hacé clic en <strong class="text-white">Start</strong> y minimizalo.</li>
                    <li>Podés seguir <a href="https://www.youtube.com/watch?v=gMfCLvMa7MI" target="_blank" rel="noopener" class="text-gsaccent hover:underline">este video</a> como guía.</li>
                </ol>
                <p class="text-slate-500 text-xs">Tip: desactivar overlays (Discord/GeForce) puede ayudar si seguís viendo caídas de FPS.</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                Instalar CoD2x (recomendado)
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p class="text-slate-300 font-medium">Cómo instalarlo (cliente en Windows)</p>
                <ol class="list-decimal list-inside space-y-1.5">
                    <li>Necesitás Call of Duty 2 original con la versión <strong class="text-white">1.3</strong> instalada.</li>
                    <li>Descargá la última build de CoD2x desde <a href="https://cod2x.me" target="_blank" rel="noopener" class="text-gsaccent hover:underline">cod2x.me</a> (el archivo <code class="text-xs bg-panel2 border border-slate-800 rounded px-1">CoD2x_*_windows.zip</code> más reciente).</li>
                    <li>Extraé estos archivos en tu carpeta de Call of Duty 2 y reemplazá los existentes si te lo pide:
                        <ul class="list-disc list-inside ml-4 mt-1 text-slate-500">
                            <li><code class="text-xs bg-panel2 border border-slate-800 rounded px-1">mss32.dll</code></li>
                            <li><code class="text-xs bg-panel2 border border-slate-800 rounded px-1">mss32_original.dll</code></li>
                        </ul>
                    </li>
                </ol>
                @php
                    // Iconos inline en vez de <pre> de texto plano -- carpeta (amber) para
                    // directorios, archivo (slate) para el resto, y archivo con check
                    // (emerald) para los 2 .dll que hay que reemplazar, calcando la
                    // captura de referencia que paso el dueño.
                    $iconFolder = '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>';
                    $iconFile = '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>';
                    $iconFileCheck = $iconFile.'<path d="m9.5 14.5 1.5 1.5 3-3"/>';
                @endphp
                <p>La estructura final debería incluir entradas como:</p>
                <div class="rounded-lg bg-panel2 border border-slate-800 text-xs px-3 py-2.5 overflow-x-auto">
                    <div class="flex items-center gap-1.5 text-gsaccent font-medium">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconFolder !!}</svg>
                        Call of Duty 2/
                    </div>
                    <div class="pl-4 space-y-1 mt-1">
                        @foreach(['Docs/', 'main/', 'miles/', 'pb/'] as $folder)
                            <div class="flex items-center gap-1.5 text-slate-500">
                                <span class="text-slate-700">—</span>
                                <svg class="w-3.5 h-3.5 shrink-0 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconFolder !!}</svg>
                                {{ $folder }}
                            </div>
                        @endforeach
                        @foreach(['CoD2MP_s.exe', 'CoD2SP_s.exe', 'gfx_d3d_mp_x86_s.dll', 'gfx_d3d_x86_s.dll'] as $file)
                            <div class="flex items-center gap-1.5 text-slate-400">
                                <span class="text-slate-700">—</span>
                                <svg class="w-3.5 h-3.5 shrink-0 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconFile !!}</svg>
                                {{ $file }}
                            </div>
                        @endforeach
                        @foreach(['mss32.dll', 'mss32_original.dll'] as $file)
                            <div class="flex items-center gap-1.5 text-emerald-400">
                                <span class="text-slate-700">—</span>
                                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconFileCheck !!}</svg>
                                {{ $file }}
                            </div>
                        @endforeach
                        <div class="flex items-center gap-1.5 text-slate-600">
                            <span class="text-slate-700">—</span>
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconFile !!}</svg>
                            ... (otros archivos)
                        </div>
                    </div>
                </div>
                <p class="text-slate-500 text-xs">El archivo descargado también puede incluir</p>
                <div class="inline-flex items-center gap-1.5 px-2 py-1 rounded bg-panel2 border border-slate-800 text-xs text-slate-400">
                    <svg class="w-3.5 h-3.5 shrink-0 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $iconFile !!}</svg>
                    CoD2x Installation and uninstallation manual.txt
                </div>
                <p class="text-slate-600 text-xs">, que no es necesario para instalar.</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                ¿Pantalla negra al iniciar el juego?
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>Es un problema conocido en versiones modernas de Windows. Instalá CoD2x (ver arriba) para solucionarlo. Si persiste:</p>
                <ol class="list-decimal list-inside space-y-1.5">
                    <li>Clic derecho en <code class="text-xs bg-panel2 border border-slate-800 rounded px-1">CoD2MP_s.exe</code></li>
                    <li>Andá a <strong class="text-white">Propiedades &gt; Compatibilidad</strong></li>
                    <li>Marcá <strong class="text-white">Ejecutar este programa en modo de compatibilidad para Windows XP (SP3)</strong></li>
                    <li>Marcá <strong class="text-white">Deshabilitar las optimizaciones de pantalla completa</strong></li>
                </ol>
                <p class="text-slate-500 text-xs">Si tenés una GPU de laptop, forzá el modo de alto rendimiento para CoD2 en la configuración de gráficos de Windows.</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                ¿Mejor configuración de red para un hitreg fluido?
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>Usá estos valores estables en tu config:</p>
                <code class="block px-3 py-2 rounded-lg bg-panel2 border border-slate-800 text-cyan-300 text-xs">/cl_maxpackets 100 /rate 25000 /snaps 20</code>
                <p class="text-slate-500 text-xs">Mantené el FPS estable y evitá picos de pérdida de paquetes. Se recomienda conexión por cable.</p>
            </div>
        </details>
    </div>
</div>
@endsection
