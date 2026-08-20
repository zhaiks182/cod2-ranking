@if($server->systemd_service)
@php
    $latest = $resourceSamples->last();
    $cpuNow = $latest?->cpu_percent;
    $memNow = $latest ? round($latest->memory_bytes / 1048576, 1) : null;

    // Grafico de area armado a mano con SVG (linea + relleno degradado + cada
    // punto con su timestamp y valores embebidos en data-points para el
    // tooltip/crosshair de JS) -- sin libreria de graficos ni JS externo,
    // consistente con el resto del panel. Los valores se normalizan al
    // viewBox 680x100.
    $buildChart = function (array $points, float $max, float $width = 680, float $height = 100) {
        $count = count($points);
        if ($count < 2) {
            return null;
        }

        $xy = [];
        foreach ($points as $i => $p) {
            $x = ($i / ($count - 1)) * $width;
            $y = $height - (min($p['v'] / max($max, 1), 1) * $height);
            $xy[] = ['x' => round($x, 1), 'y' => round($y, 1), 'v' => $p['v'], 'series' => $p['series']];
        }

        $linePoints = implode(' ', array_map(fn ($p) => $p['x'].','.$p['y'], $xy));
        $areaPath = 'M'.$xy[0]['x'].','.$height.' L'.$linePoints.' L'.end($xy)['x'].','.$height.' Z';

        // Mostrar un marcador visible cada N muestras en vez de las 1440
        // posibles (1/min en 48h) -- de a una satura el SVG de nodos sin
        // aportar nada legible. El hover/tooltip igual usa TODOS los puntos
        // (via data-points, abajo) para "engancharse" al mas cercano al mouse
        // con precision real, no solo a estos ~40 visibles.
        $step = max(1, (int) ceil($count / 40));
        $markers = [];
        foreach ($xy as $i => $p) {
            if ($i % $step === 0 || $i === $count - 1) {
                $markers[] = $p;
            }
        }

        $maxPoint = collect($xy)->sortByDesc('v')->first();

        // data-points para el JS: x (coordenada del viewBox), t (hora
        // legible) y series (lo que se muestra en la caja del tooltip, cada
        // una con su label/valor/color ya formateados en el servidor).
        $dataPoints = array_map(fn ($p) => ['x' => $p['x'], 't' => $p['series']['t'], 'series' => $p['series']['rows']], $xy);

        return [
            'line' => $linePoints,
            'area' => $areaPath,
            'markers' => $markers,
            'maxPoint' => $maxPoint,
            'dataPoints' => $dataPoints,
        ];
    };

    $cpuColor = '#22d3ee';
    $ramColor = '#a78bfa';
    $swapColor = '#f59e0b';

    $cpuPoints = $resourceSamples->map(fn ($s) => [
        'v' => $s->cpu_percent ?? 0,
        'series' => [
            't' => $s->sampled_at->format('d/m H:i'),
            'rows' => [['label' => 'CPU', 'value' => number_format($s->cpu_percent ?? 0, 1).'%', 'color' => $cpuColor]],
        ],
    ])->values()->all();

    $memPoints = $resourceSamples->map(fn ($s) => [
        'v' => round($s->memory_bytes / 1048576, 1),
        'series' => [
            't' => $s->sampled_at->format('d/m H:i'),
            'rows' => [
                ['label' => 'RAM', 'value' => number_format($s->memory_bytes / 1048576, 1).' MB', 'color' => $ramColor],
                ['label' => 'Swap', 'value' => number_format($s->swap_bytes / 1048576, 1).' MB', 'color' => $swapColor],
            ],
        ],
    ])->values()->all();

    $cpuChart = $buildChart($cpuPoints, 100);
    // 400 = el MemoryMax configurado en el .service, se usa de referencia visual
    // aunque el pico real de las muestras sea mas bajo.
    $memMax = max(400, ...(array_column($memPoints, 'v') ?: [0]));
    $memChart = $buildChart($memPoints, $memMax);

    // Con la ventana en 48h, "solo hora" es ambiguo (¿17:05 es de hoy o de
    // ayer?) -- se agrega la fecha corta salvo que el punto sea de hoy.
    $shortLabel = fn ($dt) => $dt->isToday() ? $dt->format('H:i') : $dt->format('d/m H:i');
    $timeLabels = $resourceSamples->isNotEmpty() ? [
        'start' => $shortLabel($resourceSamples->first()->sampled_at),
        'end' => $shortLabel($resourceSamples->last()->sampled_at),
    ] : null;

    // Rango min/prom/max del periodo mostrado -- mismo dato que ya tenemos en
    // $resourceSamples, sin pedir nada nuevo. El % de CPU viene NULL en la
    // primera muestra de cada serie (no hay muestra anterior contra la cual
    // restar, ver SampleServerResources), asi que esas se excluyen del calculo
    // en vez de contarlas como 0 y falsear el minimo/promedio.
    $cpuReal = $resourceSamples->pluck('cpu_percent')->filter(fn ($v) => $v !== null)->values();
    $cpuStats = $cpuReal->isNotEmpty() ? [
        'min' => $cpuReal->min(), 'avg' => round($cpuReal->avg(), 1), 'max' => $cpuReal->max(),
    ] : null;

    $memMbValues = collect(array_column($memPoints, 'v'));
    $memStats = $memMbValues->isNotEmpty() ? [
        'min' => round($memMbValues->min(), 1), 'avg' => round($memMbValues->avg(), 1), 'max' => round($memMbValues->max(), 1),
    ] : null;
@endphp
<div id="resource-usage-widget" data-refresh-url="{{ route('admin.console.resource-usage', $server) }}" class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between">
        <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Recursos del servicio <span class="text-slate-300 font-normal normal-case">({{ $server->systemd_service }})</span></span>
        <span class="inline-flex items-center gap-1.5 text-[10px] text-slate-600">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500/70"></span>
            actualiza cada 1 min · últimas 48h
        </span>
    </div>
    <div class="p-5 space-y-6">
        <div class="grid grid-cols-2 gap-4">
            <div class="rounded-lg border border-slate-800/70 bg-slate-950/40 py-4 text-center">
                <div class="text-3xl font-semibold tabular-nums {{ $cpuNow !== null && $cpuNow >= 80 ? 'text-red-400' : 'text-cyan-400' }}">
                    {{ $cpuNow !== null ? number_format($cpuNow, 1).'%' : '—' }}
                </div>
                <div class="text-[11px] font-medium uppercase tracking-wider text-slate-500 mt-1.5">CPU</div>
            </div>
            <div class="rounded-lg border border-slate-800/70 bg-slate-950/40 py-4 text-center">
                <div class="text-3xl font-semibold tabular-nums text-cyan-400">{{ $memNow !== null ? $memNow.' MB' : '—' }}</div>
                <div class="text-[11px] font-medium uppercase tracking-wider text-slate-500 mt-1.5">RAM</div>
            </div>
        </div>

        @if($cpuChart)
            <div class="space-y-5">
                @foreach(['cpu' => ['chart' => $cpuChart, 'stats' => $cpuStats, 'label' => 'CPU % (48h)', 'stroke' => $cpuColor, 'gradId' => 'cod2-cpu-grad', 'unit' => '%'], 'ram' => ['chart' => $memChart, 'stats' => $memStats, 'label' => 'RAM MB (48h)', 'stroke' => $ramColor, 'gradId' => 'cod2-ram-grad', 'unit' => ' MB']] as $key => $c)
                    <div class="rounded-lg border border-slate-800/70 bg-slate-950/20 p-3.5">
                        <div class="flex items-baseline justify-between mb-2">
                            <span class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $c['label'] }}</span>
                            @if($c['stats'])
                                <span class="text-[11px] text-slate-600">pico <span class="text-slate-300 font-medium">{{ number_format($c['chart']['maxPoint']['v'], 1) }}{{ $c['unit'] }}</span></span>
                            @endif
                        </div>
                        <svg viewBox="0 0 680 100" class="cod2-chart-svg w-full h-24 cursor-crosshair" preserveAspectRatio="none" data-points='@json($c['chart']['dataPoints'])'>
                            <defs>
                                <linearGradient id="{{ $c['gradId'] }}" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="{{ $c['stroke'] }}" stop-opacity="0.28" />
                                    <stop offset="100%" stop-color="{{ $c['stroke'] }}" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            {{-- lineas guia horizontales (0%, 50%, 100% del alto) para dar referencia sin ejes numericos que compitan con los tiles de arriba --}}
                            <line x1="0" y1="0" x2="680" y2="0" stroke="#1e293b" stroke-width="1" />
                            <line x1="0" y1="50" x2="680" y2="50" stroke="#1e293b" stroke-width="1" stroke-dasharray="2,3" />
                            <line x1="0" y1="100" x2="680" y2="100" stroke="#1e293b" stroke-width="1" />

                            <path d="{{ $c['chart']['area'] }}" fill="url(#{{ $c['gradId'] }})" stroke="none" />
                            <polyline points="{{ $c['chart']['line'] }}" fill="none" stroke="{{ $c['stroke'] }}" stroke-width="1.5" stroke-linejoin="round" />

                            {{-- marcador del pico --}}
                            <circle cx="{{ $c['chart']['maxPoint']['x'] }}" cy="{{ $c['chart']['maxPoint']['y'] }}" r="3" fill="{{ $c['stroke'] }}" stroke="#0b1220" stroke-width="1.5" />

                            {{-- puntos visibles (subset), decorativos -- el hover real usa TODOS los puntos via data-points --}}
                            @foreach($c['chart']['markers'] as $m)
                                <circle cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="2" fill="{{ $c['stroke'] }}" opacity="0.6" />
                            @endforeach

                            {{-- linea vertical que sigue al mouse, oculta hasta que el JS la mueva --}}
                            <line class="cod2-chart-crosshair" x1="0" y1="0" x2="0" y2="100" stroke="#94a3b8" stroke-width="1" opacity="0" />
                            <circle class="cod2-chart-crosshair-dot" r="3.5" fill="{{ $c['stroke'] }}" stroke="#0b1220" stroke-width="1.5" opacity="0" />
                        </svg>
                        @if($timeLabels)
                            <div class="flex items-center justify-between text-[11px] text-slate-600 mt-1">
                                <span>{{ $timeLabels['start'] }}</span>
                                <span>{{ $timeLabels['end'] }}</span>
                            </div>
                        @endif
                        @if($c['stats'])
                            <div class="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-slate-800/70 text-center text-[11px]">
                                <div><span class="text-slate-600">Mín</span> <span class="text-slate-300 font-medium">{{ number_format($c['stats']['min'], 1) }}{{ $c['unit'] }}</span></div>
                                <div><span class="text-slate-600">Prom</span> <span class="text-slate-300 font-medium">{{ number_format($c['stats']['avg'], 1) }}{{ $c['unit'] }}</span></div>
                                <div><span class="text-slate-600">Máx</span> <span class="text-slate-300 font-medium">{{ number_format($c['stats']['max'], 1) }}{{ $c['unit'] }}</span></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-xs text-slate-600 text-center py-4">Todavía no hay suficientes muestras para el gráfico (se junta 1 por minuto).</div>
        @endif
    </div>
</div>
@endif
