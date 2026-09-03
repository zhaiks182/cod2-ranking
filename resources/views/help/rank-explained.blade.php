@extends('layouts.app')

@section('title', __('Cómo funciona el rango'))

@section('content')
<style>
    summary::-webkit-details-marker { display: none; }
</style>

<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="font-display text-2xl md:text-3xl font-bold text-white">{{ __('Cómo funciona el rango') }}</h1>
        <p class="text-sm text-slate-400 mt-1">{{ __('Explicación pública del algoritmo detrás de /rango y del balanceador de Equipos, actualizada el 2 de septiembre de 2026.') }}</p>
    </div>

    <div class="space-y-3">
        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden" open>
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                {{ __('El score: 50% Win rate + 30% K/D + 20% Impacto') }}
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>{{ __('Cada jugador con al menos :n partidas jugadas en la temporada calcula tres métricas: Win rate (partidas ganadas), K/D (bajas/muertes) e Impacto (más abajo). Cada una se convierte a un percentil de 0 a 100 comparando SOLO contra el resto de jugadores calificados de esta temporada — no una escala fija, sino tu posición relativa al resto.', ['n' => $minMatches ?? 9]) }}</p>
                <p class="font-mono text-xs bg-panel2 border border-slate-800 rounded-lg px-3 py-2 text-cyan-300">Score = (0.50 × %Winrate) + (0.30 × %K/D) + (0.20 × %Impacto)</p>
                <p>{{ __('El objetivo explícito de este balance: incentivar jugar a GANAR la ronda, no a acumular bajas sueltas sin importar el resultado.') }}</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                {{ __('¿Qué es el Impacto?') }}
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>{{ __('Puntos ganados por acciones que directamente ayudan a ganar la ronda, no solo por bajar gente. Se suman todos los puntos de la temporada y ese total también se convierte a percentil.') }}</p>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-slate-800">
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Plantar la bomba') }}</td><td class="py-1.5 text-right text-cyan-300">+1.0</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Desactivar la bomba') }}</td><td class="py-1.5 text-right text-cyan-300">+1.5</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Primera sangre de la ronda') }}</td><td class="py-1.5 text-right text-cyan-300">+1.0</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Doble kill en la misma ronda') }}</td><td class="py-1.5 text-right text-cyan-300">+1.0</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Triple kill') }}</td><td class="py-1.5 text-right text-cyan-300">+2.0</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Cuádruple kill') }}</td><td class="py-1.5 text-right text-cyan-300">+3.5</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('ACE (5+ kills)') }}</td><td class="py-1.5 text-right text-cyan-300">+5.5</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Clutch 1v1') }}</td><td class="py-1.5 text-right text-cyan-300">+1.5</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Clutch 1v2') }}</td><td class="py-1.5 text-right text-cyan-300">+2.5</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Clutch 1v3') }}</td><td class="py-1.5 text-right text-cyan-300">+4.0</td></tr>
                        <tr><td class="py-1.5 pr-3 text-slate-300">{{ __('Clutch 1v4 o más') }}</td><td class="py-1.5 text-right text-cyan-300">+6.0</td></tr>
                    </tbody>
                </table>
                <p>{{ __('Multi-kills y clutches: solo cuenta el nivel más alto alcanzado en esa ronda puntual, no se suman los niveles anteriores.') }}</p>
                <p>{{ __('Un clutch requiere las dos cosas a la vez: haber quedado como único sobreviviente de tu equipo en algún momento de la ronda frente a uno o más enemigos vivos, Y que tu equipo haya ganado esa ronda. El nivel (1v1, 1v2...) es la cantidad de enemigos vivos en el instante exacto en que quedaste solo, no al final.') }}</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                {{ __('Las insignias S / A / B / C / D') }}
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>{{ __('No son quintiles iguales (20% cada uno) -- es una distribución normal seccionada según tu POSICIÓN en la tabla ordenada por score, no el valor del score en sí:') }}</p>
                <ul class="list-disc list-inside space-y-1">
                    <li><span class="text-fuchsia-300 font-semibold">S</span> — {{ __('top 5% de la tabla') }}</li>
                    <li><span class="text-amber-300 font-semibold">A</span> — {{ __('siguiente 20%') }}</li>
                    <li><span class="text-cyan-300 font-semibold">B</span> — {{ __('el 50% del medio') }}</li>
                    <li><span class="text-orange-400 font-semibold">C</span> — {{ __('siguiente 20%') }}</li>
                    <li><span class="text-red-400 font-semibold">D</span> — {{ __('el 5% de abajo') }}</li>
                </ul>
                <p>{{ __('Solo se cuentan jugadores con rango asignado (mínimo de partidas cumplido) al calcular estos cortes.') }}</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                {{ __('Jugadores inactivos en /rango') }}
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>{{ __('Un jugador se muestra en gris, al final de la tabla (nunca oculto del todo), si cumple las DOS condiciones a la vez: no jugó en los últimos 15 días, Y sus horas jugadas esta temporada están por debajo del promedio general de horas jugadas de la temporada vigente (entre los jugadores calificados, no un promedio global de todo el sitio).') }}</p>
                <p>{{ __('Este criterio solo aplica mirando la temporada activa — al ver una temporada ya cerrada, o el histórico completo, nadie se marca como inactivo (no tendría sentido: esa temporada ya no se está jugando).') }}</p>
            </div>
        </details>

        <details class="group rounded-xl border border-slate-800 bg-panel overflow-hidden">
            <summary class="flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer list-none text-sm font-semibold text-white">
                {{ __('Equipos (/equipos) y el MMR semilla entre temporadas') }}
                <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="px-4 pb-4 text-sm text-slate-400 space-y-3">
                <p>{{ __('El balanceador de equipos usa este mismo score para armar dos equipos parejos (snake draft por score descendente) entre los jugadores conectados ahora mismo.') }}</p>
                <p>{{ __('Un jugador que todavía no llega al mínimo de partidas en la temporada actual, pero sí jugó lo suficiente en la temporada anterior, arranca con un valor interno basado 100% en el percentil de K/D que tenía en esa temporada anterior — solo para que Equipos pueda armar un balance razonable desde el primer partido, en vez de tratarlo como un desconocido total. Este valor nunca se muestra públicamente en /rango, que sigue exigiendo el mínimo de partidas de esta temporada como siempre.') }}</p>
            </div>
        </details>
    </div>
</div>
@endsection
