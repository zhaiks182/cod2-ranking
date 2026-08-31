@extends('layouts.admin')

@section('title', 'Discord')

@section('content')
<div class="max-w-xl mx-auto">
    <h1 class="text-lg font-semibold mb-1">Configuración de Discord</h1>
    <p class="text-sm text-slate-500 mb-4">Controla el módulo de Discord que se muestra en la página de inicio: a qué servidor apunta, y el texto de la sección.</p>

    <form method="POST" action="{{ route('admin.discord.update') }}" class="space-y-4 bg-panel border border-slate-800 rounded-xl p-6">
        @csrf
        @method('PUT')

        @if($errors->any())
            <div class="rounded-lg border border-red-900 bg-red-950/40 px-3 py-2 text-sm text-red-300">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Server ID de Discord</label>
            <input type="text" name="discord_guild_id" value="{{ old('discord_guild_id', $setting->discord_guild_id) }}" placeholder="1188300314026184784" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 font-mono text-sm">
            <p class="text-xs text-slate-600 mt-1">Clic derecho en el ícono del server en Discord → Copiar ID de servidor (necesita el modo desarrollador activado). El server debe tener el Widget habilitado en Configuración → Widget.</p>
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Enlace de invitación</label>
            <input type="url" name="discord_invite_url" value="{{ old('discord_invite_url', $setting->discord_invite_url) }}" placeholder="https://discord.gg/xxxxxxx" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 font-mono text-sm">
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Descripción</label>
            <textarea name="discord_description" rows="2" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm">{{ old('discord_description', $setting->discord_description) }}</textarea>
            <p class="text-xs text-slate-600 mt-1">Texto corto debajo del título "Unite a nuestro Discord".</p>
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Listado de beneficios</label>
            <textarea name="discord_benefits" rows="8" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-sm">{{ old('discord_benefits', $setting->discord_benefits) }}</textarea>
            <p class="text-xs text-slate-600 mt-1">Un ítem por línea. Se muestran como lista con ícono en la sección de Discord de la página de inicio.</p>
        </div>

        <div class="pt-4 border-t border-slate-800">
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Webhook de resultados de partidas</label>
            <input type="url" name="discord_match_webhook_url" value="{{ old('discord_match_webhook_url', $setting->discord_match_webhook_url) }}" placeholder="https://discord.com/api/webhooks/xxxxxxxxxxxx/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 font-mono text-sm">
            <p class="text-xs text-slate-600 mt-1">Cuando una partida de Search &amp; Destroy termina, se postea el resultado (mapa, marcador, MVP) al canal dueño de este webhook. Se crea desde Discord: Configuración del canal → Integraciones → Webhooks → Nuevo Webhook → Copiar URL. Dejar vacío para desactivar.</p>
        </div>

        <button type="submit" class="w-full py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-medium">Guardar</button>
    </form>
</div>
@endsection
