@extends('layouts.admin')

@section('title', 'Iniciar sesión')

@section('content')
<div class="max-w-sm mx-auto mt-16">
    <h1 class="text-lg font-semibold mb-4 text-center">Panel de administración</h1>

    <form method="POST" action="{{ route('admin.login') }}" class="space-y-4 bg-panel border border-slate-800 rounded-xl p-6">
        @csrf

        @if($errors->any())
            <div class="rounded-lg border border-red-900 bg-red-950/40 px-3 py-2 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Usuario</label>
            <input type="text" name="username" value="{{ old('username') }}" required autofocus
                class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Contraseña</label>
            <input type="password" name="password" required
                class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200">
        </div>
        <button type="submit" class="w-full py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-medium">Entrar</button>
    </form>
</div>
@endsection
