@extends('layouts.app')

@section('title', __(':name — Perfil', ['name' => $player->last_name_plain]))
@section('og_title', $player->last_name_plain.' — CoD2 Stats')
@section('og_description', $player->siteUser->bio ?: __('Perfil de :name en CoD2 Stats — Pug Latam', ['name' => $player->last_name_plain]))

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <a href="{{ route('players.show', $player) }}" class="text-xs text-blue-400 hover:text-blue-300">&larr; {{ __('Volver a las estadísticas') }}</a>

    <div class="rounded-xl border border-slate-800 bg-panel px-8 py-8">
        <div class="flex items-start gap-6 flex-wrap">
            @if($player->siteUser->avatar_url)
                <img src="{{ $player->siteUser->avatar_url }}" alt="" class="w-32 h-32 rounded-full object-cover border border-slate-700 shrink-0">
            @else
                <div class="w-32 h-32 rounded-full bg-panel2 border border-slate-700 flex items-center justify-center text-slate-600 text-sm shrink-0">{{ __('Sin foto') }}</div>
            @endif

            <div class="min-w-0">
                <h1 class="text-2xl font-semibold">{!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}</h1>
                @if($player->siteUser->clan_tag)
                    <p class="text-base text-slate-400 mt-1">{{ __('Clan') }}: {{ $player->siteUser->clan_tag }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-3">
                    <a href="https://discord.com/users/{{ $player->siteUser->discord_id }}" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1 rounded-full bg-[#5865F2]/15 text-[#5865F2] px-2.5 py-1 text-sm font-medium hover:bg-[#5865F2]/25 transition-colors">
                        Discord: {{ $player->siteUser->discord_username }}
                    </a>
                    @if($player->siteUser->role)
                        <span class="inline-flex items-center gap-1 rounded-full bg-violet-500/15 text-violet-300 px-2.5 py-1 text-sm font-medium">
                            {{ $player->siteUser->role }}
                        </span>
                    @endif
                    @if($player->siteUser->preferred_role)
                        <span class="inline-flex items-center gap-1 rounded-full bg-panel2 border border-slate-700 text-slate-300 px-2.5 py-1 text-sm">
                            {{ \App\Support\WeaponCatalog::label($player->siteUser->preferred_role) }}
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-sm text-slate-500">
                    @if($player->siteUser->country)
                        <span class="inline-flex items-center gap-1.5">
                            {!! \App\Services\GeoIp::flagIconHtml($player->siteUser->country, 20, 14) !!}
                            {{ \App\Support\CountryCatalog::OPTIONS[$player->siteUser->country] ?? strtoupper($player->siteUser->country) }}
                        </span>
                    @endif
                    @if($player->siteUser->language)
                        <span class="inline-flex items-center gap-1.5">
                            {!! \App\Services\GeoIp::flagIconHtml($player->siteUser->language === 'es' ? 'es' : 'us', 20, 14) !!}
                            {{ $player->siteUser->language === 'es' ? 'Español' : 'English' }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        @if($player->siteUser->bio)
            <p class="text-base text-slate-300 mt-6 leading-relaxed">{{ $player->siteUser->bio }}</p>
        @endif
    </div>

    @php
        // Un solo icono (flecha "abrir en otra pestaña") reusado para las 6 redes,
        // diferenciadas por su color de marca -- mas seguro que reproducir 6 logos
        // exactos a mano (un path SVG mal armado rompe/desaparece el icono).
        $socialLinks = [
            ['url' => $player->siteUser->steam_url, 'label' => 'Steam', 'color' => '#66c0f4'],
            ['url' => $player->siteUser->twitch_url, 'label' => 'Twitch', 'color' => '#9146FF'],
            ['url' => $player->siteUser->instagram_url, 'label' => 'Instagram', 'color' => '#E1306C'],
            ['url' => $player->siteUser->youtube_url, 'label' => 'YouTube', 'color' => '#FF0000'],
            ['url' => $player->siteUser->twitter_url, 'label' => 'Twitter / X', 'color' => '#1DA1F2'],
            ['url' => $player->siteUser->website_url, 'label' => __('Sitio web'), 'color' => '#94a3b8'],
        ];
        $hasSocialLinks = collect($socialLinks)->contains(fn ($s) => $s['url']);
    @endphp
    @if($hasSocialLinks)
        <div class="rounded-xl border border-slate-800 bg-panel px-8 py-6">
            <h2 class="text-sm uppercase tracking-wide text-slate-500 font-semibold mb-3">{{ __('Redes sociales') }}</h2>
            <div class="flex flex-wrap gap-3">
                @foreach($socialLinks as $social)
                    @if($social['url'])
                        <a href="{{ $social['url'] }}" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition-colors"
                            style="background-color: {{ $social['color'] }}26; color: {{ $social['color'] }};">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            {{ $social['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if($player->siteUser->pc_cpu || $player->siteUser->pc_gpu || $player->siteUser->pc_ram || $player->siteUser->pc_peripherals)
        <div class="rounded-xl border border-slate-800 bg-panel px-8 py-6">
            <h2 class="text-sm uppercase tracking-wide text-slate-500 font-semibold mb-3">{{ __('Specs de PC') }}</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-base text-slate-300">
                @if($player->siteUser->pc_cpu)<div><span class="block text-xs text-slate-500">CPU</span>{{ $player->siteUser->pc_cpu }}</div>@endif
                @if($player->siteUser->pc_gpu)<div><span class="block text-xs text-slate-500">GPU</span>{{ $player->siteUser->pc_gpu }}</div>@endif
                @if($player->siteUser->pc_ram)<div><span class="block text-xs text-slate-500">RAM</span>{{ $player->siteUser->pc_ram }}</div>@endif
                @if($player->siteUser->pc_peripherals)<div><span class="block text-xs text-slate-500">{{ __('Periféricos') }}</span>{{ $player->siteUser->pc_peripherals }}</div>@endif
            </div>
        </div>
    @endif
</div>
@endsection
