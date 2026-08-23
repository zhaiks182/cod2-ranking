@extends('layouts.app')

@section('title', 'Crear servidor')

@section('content')
<div class="max-w-xl mx-auto space-y-6">
    <div>
        <h1 class="font-display text-2xl md:text-3xl font-bold text-white">Crear tu servidor</h1>
        <p class="text-sm text-slate-400 mt-1">Un servidor de CoD2 propio, listo en segundos. Se apaga solo a las {{ config('hosted_servers.expiry_hours') }} horas o si queda vacío {{ config('hosted_servers.idle_minutes') }} minutos.</p>
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
            <input type="text" name="hostname" id="hostname" value="{{ old('hostname') }}" maxlength="32" required
                placeholder="Mi server"
                class="w-full px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-slate-200 text-sm focus:outline-none focus:border-cyan-500">
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
                <label for="map" class="block text-xs font-medium uppercase tracking-wide text-slate-400 mb-1">Mapa</label>
                <select name="map" id="map" required class="w-full px-3 py-2 rounded-lg bg-panel2 border border-slate-700 text-slate-200 text-sm focus:outline-none focus:border-cyan-500">
                    @foreach ($maps as $code => $label)
                        <option value="{{ $code }}" @selected(old('map') === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
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

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold transition-colors">Crear servidor</button>

        <p class="text-[11px] text-slate-500">Máximo {{ config('hosted_servers.max_concurrent') }} servidores temporales activos al mismo tiempo entre todos los visitantes — si no hay lugar, probá de nuevo en un rato.</p>
    </form>
</div>
@endsection
