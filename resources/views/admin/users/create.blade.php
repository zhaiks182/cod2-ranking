@extends('layouts.admin')

@section('title', 'Nuevo usuario')

@section('content')
<div class="max-w-xl mx-auto">
    <h1 class="text-lg font-semibold mb-1">Nuevo usuario</h1>
    <p class="text-sm text-slate-500 mb-4">Crea una cuenta más para el panel admin, con acceso solo a los módulos que elijas.</p>

    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4 bg-panel border border-slate-800 rounded-xl p-6">
        @csrf
        @include('admin.users._form')
        <button type="submit" class="w-full py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-medium">Crear usuario</button>
    </form>
</div>
@endsection
