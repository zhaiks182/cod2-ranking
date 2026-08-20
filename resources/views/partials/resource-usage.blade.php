@if($server->systemd_service)
@php
    $latest = $resourceSamples->last();
    $cpuNow = $latest?->cpu_percent;
    $memNow = $latest ? round($latest->memory_bytes / 1048576, 1) : null;
    $swapNow = $latest ? round($latest->swap_bytes / 1048576, 1) : null;

    // Sparkline armado a mano con SVG, sin libreria de graficos -- consistente
    // con el resto del panel, que no depende de JS externo salvo lo minimo.
    // Los puntos se normalizan al viewBox 680x100 en base al valor maximo dado.
    $buildSparkline = function (array $values, float $max, float $width = 680, float $height = 100): ?string {
        $count = count($values);
        if ($count < 2) {
            return null;
        }
        $points = [];
        foreach ($values as $i => $v) {
            $x = ($i / ($count - 1)) * $width;
            $y = $height - (min($v / max($max, 1), 1) * $height);
            $points[] = round($x, 1).','.round($y, 1);
        }

        return implode(' ', $points);
    };

    $cpuValues = $resourceSamples->pluck('cpu_percent')->map(fn ($v) => $v ?? 0)->values()->all();
    $memValues = $resourceSamples->pluck('memory_bytes')->map(fn ($v) => $v / 1048576)->values()->all();

    $cpuPoints = $buildSparkline($cpuValues, 100);
    // 400 = el MemoryMax configurado en el .service, se usa de referencia visual
    // aunque el pico real de las muestras sea mas bajo.
    $memPoints = $buildSparkline($memValues, max(400, ...($memValues ?: [0])));

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
    <div class="p-4 space-y-4">
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

        @if($cpuPoints)
            <div>
                <div class="text-[10px] uppercase tracking-wide text-slate-600 mb-1">CPU % (24h)</div>
                <svg viewBox="0 0 680 100" class="w-full h-20" preserveAspectRatio="none">
                    <polyline points="{{ $cpuPoints }}" fill="none" stroke="#22d3ee" stroke-width="1.5" />
                </svg>
                @if($cpuStats)
                    <div class="grid grid-cols-3 gap-2 mt-1.5 text-center">
                        <div><span class="text-slate-600">Mín</span> <span class="text-slate-300 font-medium">{{ number_format($cpuStats['min'], 1) }}%</span></div>
                        <div><span class="text-slate-600">Prom</span> <span class="text-slate-300 font-medium">{{ number_format($cpuStats['avg'], 1) }}%</span></div>
                        <div><span class="text-slate-600">Máx</span> <span class="text-slate-300 font-medium">{{ number_format($cpuStats['max'], 1) }}%</span></div>
                    </div>
                @endif
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-slate-600 mb-1">RAM MB (24h)</div>
                <svg viewBox="0 0 680 100" class="w-full h-20" preserveAspectRatio="none">
                    <polyline points="{{ $memPoints }}" fill="none" stroke="#a78bfa" stroke-width="1.5" />
                </svg>
                @if($memStats)
                    <div class="grid grid-cols-3 gap-2 mt-1.5 text-center">
                        <div><span class="text-slate-600">Mín</span> <span class="text-slate-300 font-medium">{{ $memStats['min'] }} MB</span></div>
                        <div><span class="text-slate-600">Prom</span> <span class="text-slate-300 font-medium">{{ $memStats['avg'] }} MB</span></div>
                        <div><span class="text-slate-600">Máx</span> <span class="text-slate-300 font-medium">{{ $memStats['max'] }} MB</span></div>
                    </div>
                @endif
            </div>
        @else
            <div class="text-xs text-slate-600 text-center py-4">Todavía no hay suficientes muestras para el gráfico (se junta 1 por minuto).</div>
        @endif
    </div>
</div>
@endif
