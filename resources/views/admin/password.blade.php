@extends('layouts.admin')

@section('title', 'Cambiar contraseña')

@section('content')
<div class="max-w-sm mx-auto">
    <h1 class="text-lg font-semibold mb-4">Cambiar contraseña</h1>

    <form method="POST" action="{{ route('admin.password.update') }}" class="space-y-4 bg-panel border border-slate-800 rounded-xl p-6">
        @csrf
        @method('PUT')

        @if($errors->any())
            <div class="rounded-lg border border-red-900 bg-red-950/40 px-3 py-2 text-sm text-red-300">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Contraseña actual</label>
            <input type="password" name="current_password" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Contraseña nueva</label>
            <input type="password" name="password" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200">
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Confirmar contraseña nueva</label>
            <input type="password" name="password_confirmation" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200">
        </div>
        <button type="submit" class="w-full py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-medium">Actualizar</button>
    </form>
</div>
@endsection
