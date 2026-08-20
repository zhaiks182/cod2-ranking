@if($server->systemd_service)
@php
    $latest = $resourceSamples->last();
    $cpuNow = $latest?->cpu_percent;
    $memNow = $latest ? round($latest->memory_bytes / 1048576, 1) : null;
    $swapNow = $latest ? round($latest->swap_bytes / 1048576, 1) : null;

    // Grafico de area armado a mano con SVG (linea + relleno degradado + puntos
    // con tooltip nativo del navegador via <title>, sin libreria de graficos ni
    // JS) -- consistente con el resto del panel, que evita dependencias
    // externas salvo lo minimo. Los valores se normalizan al viewBox 680x120.
    $buildChart = function (array $values, array $samples, float $max, string $unit, float $width = 680, float $height = 100) {
        $count = count($values);
        if ($count < 2) {
            return null;
        }

        $xy = [];
        foreach ($values as $i => $v) {
            $x = ($i / ($count - 1)) * $width;
            $y = $height - (min($v / max($max, 1), 1) * $height);
            $xy[] = ['x' => round($x, 1), 'y' => round($y, 1), 'v' => $v, 'sample' => $samples[$i]];
        }

        $linePoints = implode(' ', array_map(fn ($p) => $p['x'].','.$p['y'], $xy));
        $areaPath = 'M'.$xy[0]['x'].','.$height.' L'.$linePoints.' L'.end($xy)['x'].','.$height.' Z';

        // Mostrar un punto/tooltip cada N muestras en vez de las 1440 posibles
        // (1 por minuto en 24h) -- de a una satura el SVG de nodos sin agregar
        // nada legible. ~40 marcadores alcanza para ver la forma real sin
        // sobrecargar el DOM.
        $step = max(1, (int) ceil($count / 40));
        $markers = [];
        foreach ($xy as $i => $p) {
            if ($i % $step === 0 || $i === $count - 1) {
                $markers[] = $p;
            }
        }

        $maxPoint = collect($xy)->sortByDesc('v')->first();

        return [
            'line' => $linePoints,
            'area' => $areaPath,
            'markers' => $markers,
            'maxPoint' => $maxPoint,
            'unit' => $unit,
            'startLabel' => $samples[0]->sampled_at->format('H:i'),
            'endLabel' => end($samples)->sampled_at->format('H:i'),
        ];
    };

    $samples = $resourceSamples->values()->all();
    $cpuValues = $resourceSamples->pluck('cpu_percent')->map(fn ($v) => $v ?? 0)->values()->all();
    $memValues = $resourceSamples->pluck('memory_bytes')->map(fn ($v) => $v / 1048576)->values()->all();

    $cpuChart = $buildChart($cpuValues, $samples, 100, '%');
    // 400 = el MemoryMax configurado en el .service, se usa de referencia visual
    // aunque el pico real de las muestras sea mas bajo.
    $memChart = $buildChart($memValues, $samples, max(400, ...($memValues ?: [0])), ' MB');

    // Rango min/prom/max del periodo mostrado -- mismo dato que ya tenemos en
    // $resourceSamples, sin pedir nada nuevo. El % de CPU viene NULL en la
    // primera muestra de cada serie (no hay muestra anterior contra la cual
    // restar, ver SampleServerResources), asi que esas se excluyen del calculo
    // en vez de contarlas como 0 y falsear el minimo/promedio.
    $cpuReal = $resourceSamples->pluck('cpu_percent')->filter(fn ($v) => $v !== null)->values();
    $cpuStats = $cpuReal->isNotEmpty() ? [
        'min' => $cpuReal->min(),
        'avg' => round($cpuReal->avg(), 1),
        'max' => $cpuReal->max(),
    ] : null;

    $memMbValues = collect($memValues);
    $memStats = $memMbValues->isNotEmpty() ? [
        'min' => round($memMbValues->min(), 1),
        'avg' => round($memMbValues->avg(), 1),
        'max' => round($memMbValues->max(), 1),
    ] : null;
@endphp
<div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800 text-xs uppercase tracking-wide text-slate-400">
        Recursos del servicio ({{ $server->systemd_service }}) — últimas 24h
    </div>
    <div class="p-4 space-y-5">
        <div class="grid grid-cols-3 gap-4 text-center">
            <div>
                <div class="text-2xl font-semibold {{ $cpuNow !== null && $cpuNow >= 80 ? 'text-red-400' : 'text-cyan-400' }}">
                    {{ $cpuNow !== null ? number_format($cpuNow, 1).'%' : '—' }}
                </div>
                <div class="text-[11px] uppercase tracking-wide text-slate-500 mt-1">CPU</div>
            </div>
            <div>
                <div class="text-2xl font-semibold text-cyan-400">{{ $memNow !== null ? $memNow.' MB' : '—' }}</div>
                <div class="text-[11px] uppercase tracking-wide text-slate-500 mt-1">RAM</div>
            </div>
            <div>
                <div class="text-2xl font-semibold {{ ($swapNow ?? 0) > 0 ? 'text-amber-400' : 'text-emerald-400' }}">
                    {{ $swapNow !== null ? $swapNow.' MB' : '—' }}
                </div>
                <div class="text-[11px] uppercase tracking-wide text-slate-500 mt-1">Swap</div>
            </div>
        </div>

        @if($cpuChart)
            @foreach(['cpu' => ['chart' => $cpuChart, 'stats' => $cpuStats, 'label' => 'CPU % (24h)', 'stroke' => '#22d3ee', 'gradId' => 'cod2-cpu-grad'], 'ram' => ['chart' => $memChart, 'stats' => $memStats, 'label' => 'RAM MB (24h)', 'stroke' => '#a78bfa', 'gradId' => 'cod2-ram-grad']] as $key => $c)
                <div>
                    <div class="flex items-baseline justify-between mb-1">
                        <span class="text-[10px] uppercase tracking-wide text-slate-600">{{ $c['label'] }}</span>
                        @if($c['stats'])
                            <span class="text-[10px] text-slate-600">pico <span class="text-slate-400 font-medium">{{ number_format($c['chart']['maxPoint']['v'], 1) }}{{ $c['chart']['unit'] }}</span></span>
                        @endif
                    </div>
                    <svg viewBox="0 0 680 108" class="w-full h-24" preserveAspectRatio="none">
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

                        {{-- puntos con tooltip nativo (hover en desktop, tap-and-hold en mobile) --}}
                        @foreach($c['chart']['markers'] as $m)
                            <circle cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="7" fill="transparent" class="hover:fill-white/5">
                                <title>{{ $m['sample']->sampled_at->format('d/m H:i') }} — {{ number_format($m['v'], 1) }}{{ $c['chart']['unit'] }}</title>
                            </circle>
                        @endforeach
                    </svg>
                    <div class="flex items-center justify-between text-[10px] text-slate-700 -mt-1">
                        <span>{{ $c['chart']['startLabel'] }}</span>
                        <span>{{ $c['chart']['endLabel'] }}</span>
                    </div>
                    @if($c['stats'])
                        <div class="grid grid-cols-3 gap-2 mt-1.5 text-center">
                            <div><span class="text-slate-600">Mín</span> <span class="text-slate-300 font-medium">{{ number_format($c['stats']['min'], 1) }}{{ $c['chart']['unit'] }}</span></div>
                            <div><span class="text-slate-600">Prom</span> <span class="text-slate-300 font-medium">{{ number_format($c['stats']['avg'], 1) }}{{ $c['chart']['unit'] }}</span></div>
                            <div><span class="text-slate-600">Máx</span> <span class="text-slate-300 font-medium">{{ number_format($c['stats']['max'], 1) }}{{ $c['chart']['unit'] }}</span></div>
                        </div>
                    @endif
                </div>
            @endforeach
        @else
            <div class="text-xs text-slate-600 text-center py-4">Todavía no hay suficientes muestras para el gráfico (se junta 1 por minuto).</div>
        @endif
    </div>
</div>
@endif
