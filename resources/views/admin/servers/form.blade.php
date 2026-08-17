@extends('layouts.admin')

@section('title', $server->exists ? 'Editar servidor' : 'Nuevo servidor')

@section('content')
<div class="max-w-2xl">
    <h1 class="text-lg font-semibold mb-4">{{ $server->exists ? 'Editar '.$server->name : 'Nuevo servidor' }}</h1>

    <form method="POST" action="{{ $server->exists ? route('admin.servers.update', $server) : route('admin.servers.store') }}" class="space-y-4 bg-panel border border-slate-800 rounded-xl p-6">
        @csrf
        @if($server->exists) @method('PUT') @endif

        @if($errors->any())
            <div class="rounded-lg border border-red-900 bg-red-950/40 px-3 py-2 text-sm text-red-300">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Nombre</label>
                <input type="text" name="name" value="{{ old('name', $server->name) }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Slug (para la URL)</label>
                <input type="text" name="slug" value="{{ old('slug', $server->slug) }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
            </div>
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Ruta de games_mp.log en el VPS</label>
            <input type="text" name="log_path" value="{{ old('log_path', $server->log_path) }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">RCON host</label>
                <input type="text" name="rcon_host" value="{{ old('rcon_host', $server->rcon_host ?? '127.0.0.1') }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
            </div>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">RCON puerto</label>
                <input type="number" name="rcon_port" value="{{ old('rcon_port', $server->rcon_port) }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
            </div>
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">RCON contraseña @if($server->exists)<span class="normal-case text-slate-600">(dejar vacío para no cambiarla)</span>@endif</label>
            <input type="password" name="rcon_password" autocomplete="new-password" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">IP pública para conectar (/connect)</label>
                <input type="text" name="connect_ip" value="{{ old('connect_ip', $server->connect_ip) }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
            </div>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Puerto público</label>
                <input type="number" name="connect_port" value="{{ old('connect_port', $server->connect_port ?? 28960) }}" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
            </div>
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Contraseña del servidor (opcional) <span class="normal-case text-slate-600">— si el server pide clave para entrar, se muestra en "/connect" junto a la IP</span></label>
            <input type="text" name="join_password" value="{{ old('join_password', $server->join_password) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 font-mono">
        </div>

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Máximo de jugadores</label>
            <input type="number" name="max_clients" value="{{ old('max_clients', $server->max_clients ?? 30) }}" required class="w-40 bg-panel2 border border-slate-700 rounded-lg px-3 py-2">
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $server->is_active ?? true) ? 'checked' : '' }} class="rounded border-slate-700 bg-panel2">
            Activo (se muestra en el sitio público y se incluye en el parser)
        </label>

        <button type="submit" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-medium">Guardar</button>
    </form>
</div>
@endsection
