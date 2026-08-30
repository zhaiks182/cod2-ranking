@extends('layouts.app')

@section('title', __('Descargas'))

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-display text-2xl md:text-3xl font-bold text-white">{{ __('Descargas') }}</h1>
        <p class="text-sm text-slate-400 mt-1">{{ __('Todo lo que necesitás para instalar y jugar en Pug Latam.') }}</p>
    </div>

    <div class="grid sm:grid-cols-3 gap-6">
        <div class="rounded-xl border border-slate-800 bg-panel p-8 text-center flex flex-col items-center">
            <div class="w-16 h-16 rounded-xl bg-gsprimary/20 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-gsaccent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" x2="10" y1="11" y2="11"/><line x1="8" x2="8" y1="9" y2="13"/><line x1="15" x2="15.01" y1="12" y2="12"/><line x1="18" x2="18.01" y1="10" y2="10"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"/></svg>
            </div>
            <h2 class="text-lg font-semibold text-white">Game Client</h2>
            <p class="text-sm text-slate-400 mt-1.5 mb-5">{{ __('Cliente completo de Call of Duty 2 (v1.3)') }}</p>
            <a href="https://drive.google.com/uc?export=download&id=11AQTBeo2lv0_095_rvd2Jgy4Y4YQpq4s" target="_blank" rel="noopener"
                class="mt-auto inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-gsprimary hover:bg-blue-700 text-white text-sm font-semibold transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Download
            </a>
        </div>

        <div class="rounded-xl border border-slate-800 bg-panel p-8 text-center flex flex-col items-center">
            <div class="w-16 h-16 rounded-xl bg-emerald-500/20 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" x2="12" y1="22.08" y2="12"/></svg>
            </div>
            <h2 class="text-lg font-semibold text-white">CoD2x Patch</h2>
            <p class="text-sm text-slate-400 mt-1.5 mb-5">{{ __('Corrige la pantalla negra y mejora el rendimiento') }}</p>
            <a href="https://cod2x.me/" target="_blank" rel="noopener"
                class="mt-auto inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Download
            </a>
        </div>

        <div class="rounded-xl border border-slate-800 bg-panel p-8 text-center flex flex-col items-center">
            <div class="w-16 h-16 rounded-xl bg-purple-500/20 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-purple-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h5l2 2h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg>
            </div>
            <h2 class="text-lg font-semibold text-white">Mod Pack</h2>
            <p class="text-sm text-slate-400 mt-1.5 mb-5">{{ __('Mapas y mods personalizados para nuestro server') }}</p>
            <a href="{{ route('downloads.browse', ['path' => 'main']) }}" target="_blank" rel="noopener"
                class="mt-auto inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Download
            </a>
        </div>
    </div>

    <p class="text-xs text-slate-500">{!! __('Instrucciones de instalación paso a paso en las :link.', ['link' => '<a href="'.route('faq').'" class="text-gsaccent hover:underline">'.__('preguntas frecuentes').'</a>']) !!}</p>
</div>
@endsection
