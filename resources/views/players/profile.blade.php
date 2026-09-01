@extends('layouts.app')

@section('title', __(':name — Perfil', ['name' => $player->last_name_plain]))
@section('og_title', $player->last_name_plain.' — CoD2 Stats')
@section('og_description', $player->siteUser->bio ?: __('Perfil de :name en CoD2 Stats — Pug Latam', ['name' => $player->last_name_plain]))

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <a href="{{ route('players.show', $player) }}" class="text-xs text-slate-500 hover:text-cyan-400">&larr; {{ __('Volver a las estadísticas') }}</a>

    <div class="rounded-xl border border-slate-800 bg-panel px-6 py-6">
        <div class="flex items-start gap-5 flex-wrap">
            @if($player->siteUser->avatar_url)
                <img src="{{ $player->siteUser->avatar_url }}" alt="" class="w-24 h-24 rounded-full object-cover border border-slate-700 shrink-0">
            @else
                <div class="w-24 h-24 rounded-full bg-panel2 border border-slate-700 flex items-center justify-center text-slate-600 text-xs shrink-0">{{ __('Sin foto') }}</div>
            @endif

            <div class="min-w-0">
                <h1 class="text-xl font-semibold">
                    {!! \App\Support\Cod2Colors::toHtml($player->last_name) !!}
                    @if($player->siteUser->clan_tag)<span class="text-slate-500">[{{ $player->siteUser->clan_tag }}]</span>@endif
                </h1>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1">
                    <a href="https://discord.com/users/{{ $player->siteUser->discord_id }}" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1 rounded-full bg-[#5865F2]/15 text-[#5865F2] px-2 py-0.5 text-xs font-medium hover:bg-[#5865F2]/25 transition-colors">
                        Discord: {{ $player->siteUser->discord_username }}
                    </a>
                    @if($player->siteUser->role)
                        <span class="inline-flex items-center gap-1 rounded-full bg-violet-500/15 text-violet-300 px-2 py-0.5 text-xs font-medium">
                            {{ $player->siteUser->role }}
                        </span>
                    @endif
                    @if($player->siteUser->preferred_role)
                        <span class="inline-flex items-center gap-1 rounded-full bg-panel2 border border-slate-700 text-slate-300 px-2 py-0.5 text-xs">
                            {{ $player->siteUser->preferred_role }}
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-xs text-slate-500">
                    @if($player->siteUser->country)<span>{{ __('País') }}: {{ strtoupper($player->siteUser->country) }}</span>@endif
                    @if($player->siteUser->language)<span>{{ __('Idioma') }}: {{ $player->siteUser->language === 'es' ? 'Español' : 'English' }}</span>@endif
                </div>
            </div>
        </div>

        @if($player->siteUser->bio)
            <p class="text-sm text-slate-300 mt-5 leading-relaxed">{{ $player->siteUser->bio }}</p>
        @endif
    </div>

    @if($player->siteUser->steam_url || $player->siteUser->twitch_url || $player->siteUser->instagram_url || $player->siteUser->youtube_url || $player->siteUser->twitter_url || $player->siteUser->website_url)
        <div class="rounded-xl border border-slate-800 bg-panel px-6 py-5">
            <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold mb-3">{{ __('Redes sociales') }}</h2>
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                @if($player->siteUser->steam_url)<a href="{{ $player->siteUser->steam_url }}" target="_blank" rel="noopener" class="text-slate-300 hover:text-gsaccent">Steam</a>@endif
                @if($player->siteUser->twitch_url)<a href="{{ $player->siteUser->twitch_url }}" target="_blank" rel="noopener" class="text-slate-300 hover:text-gsaccent">Twitch</a>@endif
                @if($player->siteUser->instagram_url)<a href="{{ $player->siteUser->instagram_url }}" target="_blank" rel="noopener" class="text-slate-300 hover:text-gsaccent">Instagram</a>@endif
                @if($player->siteUser->youtube_url)<a href="{{ $player->siteUser->youtube_url }}" target="_blank" rel="noopener" class="text-slate-300 hover:text-gsaccent">YouTube</a>@endif
                @if($player->siteUser->twitter_url)<a href="{{ $player->siteUser->twitter_url }}" target="_blank" rel="noopener" class="text-slate-300 hover:text-gsaccent">Twitter / X</a>@endif
                @if($player->siteUser->website_url)<a href="{{ $player->siteUser->website_url }}" target="_blank" rel="noopener" class="text-slate-300 hover:text-gsaccent">{{ __('Sitio web') }}</a>@endif
            </div>
        </div>
    @endif

    @if($player->siteUser->pc_cpu || $player->siteUser->pc_gpu || $player->siteUser->pc_ram || $player->siteUser->pc_peripherals)
        <div class="rounded-xl border border-slate-800 bg-panel px-6 py-5">
            <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold mb-3">{{ __('Specs de PC') }}</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm text-slate-300">
                @if($player->siteUser->pc_cpu)<div><span class="block text-xs text-slate-500">CPU</span>{{ $player->siteUser->pc_cpu }}</div>@endif
                @if($player->siteUser->pc_gpu)<div><span class="block text-xs text-slate-500">GPU</span>{{ $player->siteUser->pc_gpu }}</div>@endif
                @if($player->siteUser->pc_ram)<div><span class="block text-xs text-slate-500">RAM</span>{{ $player->siteUser->pc_ram }}</div>@endif
                @if($player->siteUser->pc_peripherals)<div><span class="block text-xs text-slate-500">{{ __('Periféricos') }}</span>{{ $player->siteUser->pc_peripherals }}</div>@endif
            </div>
        </div>
    @endif
</div>
@endsection
