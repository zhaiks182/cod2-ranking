@extends('layouts.app')

@section('title', __('Subir a la galería'))

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <a href="{{ route('gallery.index') }}" class="text-xs text-blue-400 hover:text-blue-300">&larr; {{ __('Volver a la galería') }}</a>

    <h1 class="text-lg font-semibold">{{ __('Subir video o imagen') }}</h1>

    <p class="text-sm text-slate-400">{{ __('Te quedan :remaining MB de tu cuota de :limit MB.', ['remaining' => $remainingMb, 'limit' => $limitMb]) }}</p>
    <p class="text-xs text-slate-500 -mt-4">{{ __('Los videos no pueden pesar más de :max MB.', ['max' => $videoMaxMb]) }}</p>

    @if($errors->any())
        <div class="rounded-lg border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('gallery.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Título') }}</label>
            <input type="text" name="title" required maxlength="120" value="{{ old('title') }}"
                class="w-full rounded-lg bg-panel2 border border-slate-700 px-3 py-2 text-sm text-slate-200">
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Archivo (mp4, webm, jpg, png, webp o gif)') }}</label>
            <input type="file" name="file" required accept=".mp4,.webm,.jpg,.jpeg,.png,.webp,.gif"
                class="w-full text-sm text-slate-300 file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-cyan-600 file:text-white file:text-xs file:font-semibold">
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Partida vinculada (opcional)') }}</label>
            <select name="match_id" class="w-full rounded-lg bg-panel2 border border-slate-700 px-3 py-2 text-sm text-slate-200">
                <option value="">{{ __('Ninguna') }}</option>
                @foreach($matches as $match)
                    <option value="{{ $match->id }}" @selected(old('match_id') == $match->id)>
                        {{ \App\Support\MapCatalog::mapLabel($match->map) }} — {{ $match->started_at->format('d/m/Y H:i') }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">{{ __('Subir') }}</button>
    </form>
</div>
@endsection
