@extends('layouts.app')

@section('title', __('Editar'))

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <a href="{{ route('gallery.show', $galleryItem) }}" class="text-xs text-blue-400 hover:text-blue-300">&larr; {{ __('Volver') }}</a>

    <h1 class="text-lg font-semibold">{{ __('Editar') }}</h1>

    @if($errors->any())
        <div class="rounded-lg border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('gallery.update', $galleryItem) }}" class="space-y-4">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Título') }}</label>
            <input type="text" name="title" required maxlength="120" value="{{ old('title', $galleryItem->title) }}"
                class="w-full rounded-lg bg-panel2 border border-slate-700 px-3 py-2 text-sm text-slate-200">
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-500 mb-1">{{ __('Categoría (opcional)') }}</label>
            <select name="category" class="w-full rounded-lg bg-panel2 border border-slate-700 px-3 py-2 text-sm text-slate-200">
                <option value="">{{ __('Sin categoría') }}</option>
                @foreach(\App\Support\GalleryCategory::OPTIONS as $code => $label)
                    <option value="{{ $code }}" @selected(old('category', $galleryItem->category) === $code)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">{{ __('Guardar') }}</button>
    </form>
</div>
@endsection
