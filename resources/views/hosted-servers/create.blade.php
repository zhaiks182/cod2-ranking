@extends('layouts.app')

@section('title', 'Crear servidor')

@section('content')
<div class="max-w-xl mx-auto space-y-6">
    {{-- Banner -- usa el logo ya existente del repo (public/logo_cod2.webp), no una
    imagen de un sitio de terceros, para no reproducir el arte compuesto de otro
    hosting como si fuera nuestro. --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-800 h-36 sm:h-44 flex items-center justify-center"
        style="background: radial-gradient(120% 140% at 50% 20%, #1e293b 0%, #0b1220 60%, #05070d 100%);">
        <div class="absolute inset-0 opacity-40" style="background-image: linear-gradient(180deg, transparent 40%, #05070d 100%);"></div>
        <img src="{{ asset('logo_cod2.webp') }}" alt="Call of Duty 2" class="relative w-40 sm:w-52 drop-shadow-[0_4px_12px_rgba(0,0,0,0.6)]">
    </div>

    <div>
        <h1 class="font-display text-2xl md:text-3xl font-bold text-white">Crear tu servidor</h1>
        <p class="text-sm text-slate-400 mt-1">Un servidor de CoD2 propio, listo en segundos. Se apaga solo a las {{ config('hosted_servers.expiry_hours') }} horas o si queda vacío {{ config('hosted_servers.idle_minutes') }} minutos.</p>
    </div>

    {{-- Ubicacion real del VPS (Miami, FL, confirmado por geoip) -- informativo, no
    interactivo (a diferencia de paneles de otros hostings con selector de
    datacenter): este sitio corre en un solo VPS, no hay nada para elegir aca. --}}
    <div class="rounded-xl border border-slate-800 bg-panel px-4 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
            {!! \App\Services\GeoIp::flagIconHtml('us', 24, 18) !!}
            <span class="text-sm truncate">
                <span class="font-semibold text-white">Miami, FL</span>
                <span class="text-slate-500">, Estados Unidos</span>
            </span>
        </div>
        <span class="shrink-0 text-xs px-2.5 py-1 rounded-lg border font-medium {{ $available > 0 ? 'border-emerald-900 bg-emerald-950/30 text-emerald-400' : 'border-amber-900 bg-amber-950/30 text-amber-400' }}">
            {{ $available }} disponible{{ $available === 1 ? '' : 's' }}
        </span>
    </div>

    <div class="rounded-xl border border-amber-900 bg-amber-950/30 px-4 py-3 text-xs text-amber-300">
        Arranca en modo Search &amp; Destroy, igual que Pug Latam — necesita que <strong>2 jugadores confirmen "listo"</strong> antes de que empiece la primera ronda. Si estás probándolo solo, no va a pasar nada hasta que se sume alguien más.
    </div>

    @if (session('error'))
        <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('hosted-servers.store') }}" class="rounded-xl border border-slate-800 bg-panel p-5 space-y-4">
        @csrf

        {{-- Honeypot: invisible para una persona real (sr-only no afecta el layout, a
        diferencia de un position:absolute suelto que podia generar scroll horizontal),
        un bot que rellena todos los inputs a ciegas lo va a completar igual. --}}
        <div class="sr-only" aria-hidden="true">
            <label for="website">No completar</label>
            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label for="hostname" class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Nombre del servidor</label>
            <div class="flex items-stretch rounded-lg border border-slate-700 bg-panel2 focus-within:border-cyan-500 overflow-hidden">
                <input type="text" name="hostname" id="hostname" value="{{ old('hostname') }}" maxlength="{{ \App\Models\HostedServer::NAME_MAX_LENGTH }}" required
                    placeholder="Mi server"
                    class="min-w-0 flex-1 px-3 py-2 bg-transparent text-slate-200 text-sm focus:outline-none">
                <span class="shrink-0 flex items-center pr-3 text-sm text-slate-500 whitespace-nowrap">{{ \App\Models\HostedServer::NAME_SUFFIX }}</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">Todo servidor temporal se identifica como parte de Pug Latam — esa parte no se puede cambiar.</p>
        </div>

        <div>
            <label for="slots" class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Jugadores</label>
            <select name="slots" id="slots" required class="w-full px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-slate-200 text-sm focus:outline-none focus:border-cyan-500">
                @for ($i = $slotsMin; $i <= $slotsMax; $i++)
                    <option value="{{ $i }}" @selected(old('slots', 12) == $i)>{{ $i }}</option>
                @endfor
            </select>
        </div>

        <div>
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Mapa</span>
            <input type="hidden" name="map" id="map-select-value" value="{{ old('map') }}">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-72 overflow-y-auto p-0.5">
                @foreach ($maps as $code => $label)
                    @php $mapImageUrl = \App\Support\MapImage::url($code); @endphp
                    <button type="button" data-map-option data-code="{{ $code }}"
                        class="flex flex-col items-start gap-1.5 rounded-lg border overflow-hidden text-left transition-colors {{ old('map') === $code ? 'border-cyan-500' : 'border-slate-700 hover:border-slate-500' }}">
                        <span class="w-full aspect-video bg-panel2 flex items-center justify-center">
                            @if ($mapImageUrl)
                                <img src="{{ $mapImageUrl }}" alt="" class="w-full h-full object-cover">
                            @else
                                <svg class="w-6 h-6 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                            @endif
                        </span>
                        <span class="px-2 pb-2 text-xs font-medium text-slate-200 truncate w-full">{{ $label }}</span>
                    </button>
                @endforeach
            </div>
            @error('map')
                <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="join_password" class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Contraseña de acceso (opcional)</label>
            <input type="text" name="join_password" id="join_password" value="{{ old('join_password') }}" maxlength="32"
                placeholder="Dejalo vacío para que sea público"
                class="w-full px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-slate-200 text-sm focus:outline-none focus:border-cyan-500">
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="cracked" value="1" @checked(old('cracked')) class="rounded border-slate-700 bg-panel2 text-cyan-500 focus:ring-cyan-500">
            Permitir clientes no originales (cracked)
        </label>

        @if ($turnstileSiteKey)
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark"></div>
        @endif

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold transition-colors">Crear servidor</button>

        <p class="text-[11px] text-slate-500">Máximo {{ config('hosted_servers.max_concurrent') }} servidores temporales activos al mismo tiempo entre todos los visitantes — si no hay lugar, probá de nuevo en un rato.</p>
    </form>
</div>

@if ($turnstileSiteKey)
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif

<script>
    (function () {
        var hidden = document.getElementById('map-select-value');
        var buttons = document.querySelectorAll('[data-map-option]');

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                buttons.forEach(function (b) {
                    b.classList.remove('border-cyan-500');
                    b.classList.add('border-slate-700');
                });
                btn.classList.remove('border-slate-700');
                btn.classList.add('border-cyan-500');
                hidden.value = btn.dataset.code;
            });
        });
    })();
</script>
@endsection
