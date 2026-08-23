@extends('layouts.app')

@section('title', 'Crear servidor')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    {{-- Mismo hero que la home (fondo con mapas rotando, titulo grande) en vez de un
    banner propio -- consistencia visual entre paginas, y evita reproducir el arte
    de otro sitio de hosting (ver referencia fshost.me mencionada por el dueño). La
    ubicacion real (Miami, FL, confirmado por geoip) y la disponibilidad en vivo
    quedan en el badge de abajo, no en una tarjeta aparte. --}}
    @if (count($heroMapImages) > 0)
        <section class="relative rounded-2xl overflow-hidden border border-slate-800">
            <div id="hero-bg-carousel" class="absolute inset-0">
                @foreach ($heroMapImages as $i => $img)
                    <div class="hero-bg absolute inset-0 bg-cover bg-center transition-opacity duration-[2000ms] {{ $i === 0 ? 'opacity-100' : 'opacity-0' }}" style="background-image:url('{{ $img }}')"></div>
                @endforeach
                <div class="absolute inset-0 bg-gradient-to-t from-panel2 via-panel2/85 to-panel2/50"></div>
            </div>
            <div class="relative px-4 sm:px-6 py-10 sm:py-14 text-center">
                <h1 class="font-display text-2xl sm:text-3xl md:text-4xl font-bold text-white leading-tight">Creá tu <span class="text-gsaccent">servidor</span></h1>
                <p class="mt-3 text-[10px] sm:text-xs md:text-sm text-slate-300 uppercase tracking-[0.15em]">Listo en segundos · Se apaga solo a las {{ config('hosted_servers.expiry_hours') }} horas</p>

                <div class="mt-6 inline-flex items-center gap-2.5 px-4 py-2 rounded-full border border-slate-700 bg-panel2/60 text-xs sm:text-sm text-slate-300">
                    {!! \App\Services\GeoIp::flagIconHtml('us', 18, 13) !!}
                    <span>Miami, FL</span>
                    <span class="w-px h-3 bg-slate-700" aria-hidden="true"></span>
                    <span class="{{ $available > 0 ? 'text-emerald-400' : 'text-amber-400' }} font-medium">{{ $available }} disponible{{ $available === 1 ? '' : 's' }}</span>
                    @if ($active > 0)
                        <span class="w-px h-3 bg-slate-700" aria-hidden="true"></span>
                        <span class="inline-flex items-center gap-1.5 text-slate-400">
                            <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
                                <span class="motion-safe:animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                            </span>
                            {{ $active }} creado{{ $active === 1 ? '' : 's' }} ahora
                        </span>
                    @endif
                </div>
            </div>
        </section>
        <script>
            (function () {
                var slides = document.querySelectorAll('#hero-bg-carousel .hero-bg');
                if (slides.length < 2) return;
                var i = 0;
                setInterval(function () {
                    slides[i].classList.replace('opacity-100', 'opacity-0');
                    i = (i + 1) % slides.length;
                    slides[i].classList.replace('opacity-0', 'opacity-100');
                }, 6000);
            })();
        </script>
    @else
        <div>
            <h1 class="font-display text-2xl md:text-3xl font-bold text-white">Creá tu <span class="text-gsaccent">servidor</span></h1>
            <p class="text-sm text-slate-400 mt-1">Miami, FL · {{ $available }} disponible{{ $available === 1 ? '' : 's' }}@if($active > 0) · {{ $active }} creado{{ $active === 1 ? '' : 's' }} ahora @endif · se apaga solo a las {{ config('hosted_servers.expiry_hours') }} horas</p>
        </div>
    @endif

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

    <form method="POST" action="{{ route('hosted-servers.store') }}" class="rounded-xl border border-slate-800 bg-panel p-5 sm:p-6 space-y-5">
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

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="slots" class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Jugadores</label>
                <select name="slots" id="slots" required class="w-full px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-slate-200 text-sm focus:outline-none focus:border-cyan-500">
                    @for ($i = $slotsMin; $i <= $slotsMax; $i++)
                        <option value="{{ $i }}" @selected(old('slots', 12) == $i)>{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label for="join_password" class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Contraseña (opcional)</label>
                <input type="text" name="join_password" id="join_password" value="{{ old('join_password') }}" maxlength="32"
                    placeholder="Público si está vacío"
                    class="w-full px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-slate-200 text-sm focus:outline-none focus:border-cyan-500">
            </div>
        </div>

        <div>
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Mapa</span>
            <input type="hidden" name="map" id="map-select-value" value="{{ old('map') }}">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2.5 max-h-80 overflow-y-auto p-0.5">
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

        <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="cracked" value="1" @checked(old('cracked')) class="rounded border-slate-700 bg-panel2 text-cyan-500 focus:ring-cyan-500">
            Permitir clientes no originales (cracked)
        </label>

        @if ($turnstileSiteKey)
            <div class="flex justify-center">
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="light"></div>
            </div>
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
