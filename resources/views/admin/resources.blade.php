@extends('layouts.admin')

@section('title', 'Recursos — '.$server->name)

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-lg font-semibold">{{ $server->name }}</h1>
            <p class="text-xs text-slate-500">CPU y RAM del servicio del sistema — separado de la consola del juego.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.console.show', $server) }}" class="text-xs text-cyan-400 hover:underline">← Consola</a>
            <a href="{{ route('admin.servers.index') }}" class="text-xs text-slate-500 hover:text-slate-300">Servidores</a>
        </div>
    </div>

    @include('partials.resource-usage')
</div>

<script>
    (function () {
        // Tooltip flotante compartido por los 2 graficos de recursos (CPU/RAM).
        // Vive fuera del widget a proposito -- el auto-refresh de abajo
        // reemplaza el <div id="resource-usage-widget"> entero cada 60s, y si
        // el tooltip estuviera adentro se perderia (y con el, el listener) en
        // cada refresh. El script en si esta en esta pagina, NO dentro de
        // partials/resource-usage.blade.php, porque ese partial se vuelve a
        // renderizar solo (sin este script) en cada fetch del auto-refresh --
        // si el script estuviera ahi, se re-inyectaria y duplicaria (tooltip
        // de mas, listeners apilandose) cada 60 segundos.
        var tooltip = document.createElement('div');
        tooltip.className = 'hidden fixed z-50 rounded-lg border border-slate-700 bg-slate-900/95 px-3 py-2 text-[11px] shadow-xl pointer-events-none leading-relaxed';
        document.body.appendChild(tooltip);

        function hideResourceTooltip() {
            tooltip.classList.add('hidden');
            document.querySelectorAll('.cod2-chart-crosshair, .cod2-chart-crosshair-dot').forEach(function (el) {
                el.setAttribute('opacity', '0');
            });
        }

        // Delegado en document (no en el widget) para que siga funcionando
        // aunque el auto-refresh reemplace el <svg> por uno nuevo -- no hace
        // falta re-enganchar listeners despues de cada refresh.
        document.addEventListener('mousemove', function (e) {
            var svg = e.target.closest('.cod2-chart-svg');
            if (!svg) { hideResourceTooltip(); return; }

            var points;
            try { points = JSON.parse(svg.dataset.points || '[]'); } catch (err) { return; }
            if (!points.length) return;

            var rect = svg.getBoundingClientRect();
            var relX = ((e.clientX - rect.left) / rect.width) * 680;

            var nearest = points[0];
            var nearestDist = Math.abs(points[0].x - relX);
            for (var i = 1; i < points.length; i++) {
                var d = Math.abs(points[i].x - relX);
                if (d < nearestDist) { nearest = points[i]; nearestDist = d; }
            }

            var crosshair = svg.querySelector('.cod2-chart-crosshair');
            var dot = svg.querySelector('.cod2-chart-crosshair-dot');
            if (crosshair) {
                crosshair.setAttribute('x1', nearest.x);
                crosshair.setAttribute('x2', nearest.x);
                crosshair.setAttribute('opacity', '1');
            }
            if (dot) {
                // El Y del punto no se guarda aparte -- se lee directo de la
                // polyline ya dibujada, en el mismo indice que "nearest".
                var idx = points.indexOf(nearest);
                var poly = svg.querySelector('polyline');
                if (poly) {
                    var polyPoints = poly.getAttribute('points').trim().split(' ');
                    if (polyPoints[idx]) {
                        var xy = polyPoints[idx].split(',');
                        dot.setAttribute('cx', xy[0]);
                        dot.setAttribute('cy', xy[1]);
                        dot.setAttribute('opacity', '1');
                    }
                }
            }

            var rows = '<div class="text-slate-500 mb-1">' + nearest.t + '</div>';
            nearest.series.forEach(function (s) {
                rows += '<div class="flex items-center gap-1.5">' +
                    '<span class="w-1.5 h-1.5 rounded-full inline-block" style="background:' + s.color + '"></span>' +
                    '<span class="text-slate-400">' + s.label + ':</span> ' +
                    '<span class="text-slate-200 font-medium">' + s.value + '</span></div>';
            });
            tooltip.innerHTML = rows;
            tooltip.classList.remove('hidden');
            tooltip.style.left = (e.clientX + 14) + 'px';
            tooltip.style.top = (e.clientY - 10) + 'px';

            var tRect = tooltip.getBoundingClientRect();
            if (tRect.right > window.innerWidth) tooltip.style.left = (e.clientX - tRect.width - 14) + 'px';
            if (tRect.bottom > window.innerHeight) tooltip.style.top = (e.clientY - tRect.height - 10) + 'px';
        });

        // Auto-refresh del widget completo cada 60s -- mismo intervalo que
        // junta muestras nuevas cod2:sample-resources, refrescar mas seguido
        // no mostraria nada distinto. Mismo patron que dashboard.live-status:
        // fetch del fragmento, swap del nodo entero.
        function refreshResourceUsage() {
            var el = document.getElementById('resource-usage-widget');
            if (!el) return;
            var url = el.dataset.refreshUrl;
            if (!url) return;

            fetch(url).then(function (r) { return r.text(); }).then(function (html) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                var fresh = wrapper.querySelector('#resource-usage-widget');
                if (fresh) el.replaceWith(fresh);
            }).catch(function () {});
        }

        setInterval(refreshResourceUsage, 60000);
    })();
</script>
@endsection
