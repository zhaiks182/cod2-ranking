@extends('layouts.admin')

@section('title', 'Imágenes de mapa')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-lg font-semibold">Imágenes de mapa</h1>
        <p class="text-xs text-slate-500 mt-1">Sube una captura para cada mapa — se usa en el widget de estado en vivo en vez del degradado. Máx. 5MB, formatos jpg/png/webp.</p>
    </div>

    @php
        // Same treatment as the "mapa actual" card on the homepage live-status
        // widget (resources/views/partials/live-status.blade.php) — background-image
        // cover with the label overlaid bottom-left, and the same deterministic
        // per-map gradient fallback when no image has been uploaded yet, so a map
        // looks the same here as it will once it's live.
        $gradients = [
            'from-cyan-800 to-slate-950', 'from-fuchsia-800 to-slate-950', 'from-amber-800 to-slate-950',
            'from-emerald-800 to-slate-950', 'from-rose-800 to-slate-950', 'from-indigo-800 to-slate-950',
        ];
    @endphp
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($maps as $map)
            @php $gradient = $gradients[crc32($map['code']) % count($gradients)]; @endphp
            <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
                <div class="h-32 flex items-end p-3 bg-cover bg-center @if(!$map['url']) bg-gradient-to-br {{ $gradient }} @endif"
                    @if($map['url']) style="background-image: url('{{ $map['url'] }}')" @endif>
                    <span class="text-white font-semibold text-sm drop-shadow" style="text-shadow: 0 1px 4px rgba(0,0,0,.8)">{{ $map['label'] }}</span>
                </div>
                <div class="p-3 space-y-2">
                    <div>
                        <div class="text-sm font-medium">{{ $map['label'] }}</div>
                        <div class="text-xs text-slate-500 font-mono">{{ $map['code'] }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.maps.store', $map['code']) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                        @csrf
                        <input type="file" name="image" accept="image/*" required class="text-xs text-slate-400 flex-1 min-w-0">
                        <button type="submit" class="shrink-0 text-xs px-2 py-1 rounded border border-slate-700 hover:border-cyan-500 hover:text-cyan-400">Subir</button>
                    </form>
                    @if($map['url'])
                        <form method="POST" action="{{ route('admin.maps.destroy', $map['code']) }}" onsubmit="return confirm('¿Quitar la imagen de {{ $map['label'] }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-400 hover:underline">Quitar imagen</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
