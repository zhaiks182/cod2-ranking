@extends('layouts.app')

@section('title', __('Crear clan'))

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <h1 class="text-lg font-semibold">{{ __('Crear clan') }}</h1>

    @if($errors->any())
        <div class="rounded-lg border border-red-800 bg-red-950/40 text-red-300 text-sm px-4 py-2 space-y-1">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('clans.store') }}" enctype="multipart/form-data" class="space-y-4 rounded-xl border border-slate-800 bg-panel px-4 py-4">
        @csrf
        <div>
            <label class="block text-xs text-slate-500 mb-1">{{ __('Nombre') }}</label>
            <input type="text" name="name" value="{{ old('name') }}" maxlength="60" required class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">{{ __('Tag') }}</label>
            <input type="text" name="tag" value="{{ old('tag') }}" maxlength="15" required pattern="[A-Za-z0-9_-]+" placeholder="ej. DEST" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
            <p class="text-[11px] text-slate-600 mt-1">{{ __('Corto, sin espacios — es la parte de la URL pública del clan.') }}</p>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">{{ __('Descripción (opcional)') }}</label>
            <textarea name="description" maxlength="1000" rows="3" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">{{ old('description') }}</textarea>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">{{ __('Logo (opcional)') }}</label>
            <input type="file" name="logo" accept="image/png,image/jpeg" class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-gsprimary file:text-white file:text-xs file:font-semibold hover:file:bg-gsprimary/80">
        </div>
        <button type="submit" class="w-full px-4 py-2 rounded-lg bg-gsprimary hover:bg-gsprimary/80 text-white text-sm font-semibold">{{ __('Crear clan') }}</button>
    </form>
</div>
@endsection
