@extends('layouts.admin')

@section('title', 'Editar usuario')

@section('content')
<div class="max-w-xl mx-auto">
    <h1 class="text-lg font-semibold mb-1">Editar usuario</h1>
    <p class="text-sm text-slate-500 mb-4">{{ $user->username }}</p>

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-4 bg-panel border border-slate-800 rounded-xl p-6">
        @csrf
        @method('PUT')
        @include('admin.users._form')
        <button type="submit" class="w-full py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-medium">Guardar</button>
    </form>
</div>
@endsection
