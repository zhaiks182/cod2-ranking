@extends('layouts.app')

@section('title', __('Mi cuenta'))

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-semibold">{{ __('Completá tu perfil gaming') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ __('Esta información va a ser visible para la comunidad de CoD2.') }}</p>
    </div>

    @if(session('status'))
        <div class="rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300 text-sm px-4 py-2">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-red-800 bg-red-950/40 text-red-300 text-sm px-4 py-2">{{ session('error') }}</div>
    @endif

    @if($siteUser->player)
        {{-- Reclamado: form de edicion --}}
        <p class="text-sm text-slate-400">
            {{ __('Perfil reclamado:') }}
            <a href="{{ route('players.show', $siteUser->player) }}" class="text-cyan-400 hover:underline">{{ $siteUser->player->last_name_plain }}</a>
        </p>

        <form method="POST" action="{{ route('account.update') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Foto de perfil --}}
                <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
                    <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Foto de perfil') }}</h2>
                    <div class="flex items-center gap-4">
                        @if($siteUser->avatar_url)
                            <img src="{{ $siteUser->avatar_url }}" alt="" class="w-20 h-20 rounded-full object-cover border border-slate-700">
                        @else
                            <div class="w-20 h-20 rounded-full bg-panel2 border border-slate-700 flex items-center justify-center text-slate-600 text-xs">{{ __('Sin foto') }}</div>
                        @endif
                        <div class="text-xs text-slate-500">
                            <p>{{ __('Formatos: JPG, PNG.') }}</p>
                            <p>{{ __('Tamaño máximo: 2MB.') }}</p>
                        </div>
                    </div>
                    <input type="file" name="avatar" accept="image/png,image/jpeg" class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-gsprimary file:text-white file:text-xs file:font-semibold hover:file:bg-gsprimary/80">
                    @error('avatar')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Identidad gaming --}}
                <div class="lg:col-span-2 rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
                    <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Identidad gaming') }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">{{ __('Clan / Tag') }}</label>
                            <input type="text" name="clan_tag" value="{{ old('clan_tag', $siteUser->clan_tag) }}" maxlength="20" placeholder="ej. Destino" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                            @error('clan_tag')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">{{ __('Rol preferido') }}</label>
                            @if($usedWeapons->isEmpty())
                                <p class="text-xs text-slate-600 py-2">{{ __('Todavía no hay bajas registradas para elegir un arma.') }}</p>
                            @else
                                <select name="preferred_role" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                                    <option value="">{{ __('Sin definir') }}</option>
                                    @foreach($usedWeapons as $weapon)
                                        <option value="{{ $weapon['code'] }}" @selected(old('preferred_role', $siteUser->preferred_role) === $weapon['code'])>{{ $weapon['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                            @error('preferred_role')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">{{ __('País') }}</label>
                            <select name="country" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                                <option value="">{{ __('Sin definir') }}</option>
                                @foreach(\App\Support\CountryCatalog::OPTIONS as $code => $name)
                                    <option value="{{ $code }}" @selected(old('country', $siteUser->country) === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('country')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">{{ __('Idioma') }}</label>
                            <select name="language" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                                <option value="">{{ __('Sin definir') }}</option>
                                <option value="es" @selected(old('language', $siteUser->language) === 'es')>Español</option>
                                <option value="en" @selected(old('language', $siteUser->language) === 'en')>English</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Sobre mí') }}</label>
                        <textarea name="bio" maxlength="400" rows="3" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">{{ old('bio', $siteUser->bio) }}</textarea>
                        @error('bio')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            {{-- Specs de PC --}}
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
                <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Specs de PC') }}</h2>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">CPU</label>
                        <input type="text" name="pc_cpu" value="{{ old('pc_cpu', $siteUser->pc_cpu) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">GPU</label>
                        <input type="text" name="pc_gpu" value="{{ old('pc_gpu', $siteUser->pc_gpu) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">RAM</label>
                        <input type="text" name="pc_ram" value="{{ old('pc_ram', $siteUser->pc_ram) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Periféricos') }}</label>
                        <input type="text" name="pc_peripherals" value="{{ old('pc_peripherals', $siteUser->pc_peripherals) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                    </div>
                </div>
            </div>

            {{-- Redes sociales --}}
            <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
                <h2 class="text-xs uppercase tracking-wide text-slate-500 font-semibold">{{ __('Redes sociales') }} <span class="normal-case font-normal text-slate-600">({{ __('opcional') }})</span></h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Steam</label>
                        <input type="text" name="steam_url" value="{{ old('steam_url', $siteUser->steam_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                        @error('steam_url')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Twitch</label>
                        <input type="text" name="twitch_url" value="{{ old('twitch_url', $siteUser->twitch_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                        @error('twitch_url')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Instagram</label>
                        <input type="text" name="instagram_url" value="{{ old('instagram_url', $siteUser->instagram_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                        @error('instagram_url')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">YouTube</label>
                        <input type="text" name="youtube_url" value="{{ old('youtube_url', $siteUser->youtube_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                        @error('youtube_url')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Twitter / X</label>
                        <input type="text" name="twitter_url" value="{{ old('twitter_url', $siteUser->twitter_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                        @error('twitter_url')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ __('Sitio web') }}</label>
                        <input type="text" name="website_url" value="{{ old('website_url', $siteUser->website_url) }}" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200">
                        @error('website_url')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded-lg bg-gsprimary text-white text-sm font-semibold hover:bg-gsprimary/80">{{ __('Guardar perfil') }}</button>
                <a href="{{ route('players.show', $siteUser->player) }}" class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 text-sm hover:border-slate-500">{{ __('Cancelar') }}</a>
            </div>
        </form>
    @elseif($siteUser->hasPendingClaim())
        {{-- Reclamo pendiente: mostrar el codigo --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
            <p class="text-sm text-slate-300">
                {{ __('Escribí este código en el chat del servidor dentro de los próximos 15 minutos para confirmar que sos') }}
                <strong>{{ $siteUser->pendingClaimPlayer->last_name_plain }}</strong>:
            </p>
            <p class="text-2xl font-mono font-semibold text-cyan-400">{{ $siteUser->claim_code }}</p>

            <div class="w-full h-1.5 bg-panel2 rounded-full overflow-hidden">
                <div id="claim-progress-bar" class="h-full bg-cyan-400" style="width:100%"></div>
            </div>
            <p class="text-xs text-slate-500" id="claim-progress-label">{{ __('Vence') }}: {{ $siteUser->claim_code_expires_at->format('d/m/Y H:i') }}</p>

            <p class="text-xs text-slate-500 flex items-center gap-1.5">
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-cyan-400 motion-safe:animate-pulse"></span>
                {{ __('Esta página avisa sola apenas se confirme (podés tardar hasta 1 minuto desde que escribís el código).') }}
            </p>
            <form method="POST" action="{{ route('account.claim.cancel') }}">
                @csrf
                <button type="submit" class="text-xs text-red-400 hover:underline">{{ __('Cancelar reclamo') }}</button>
            </form>
        </div>

        {{-- Aviso flotante que aparece apenas players:check-claims (cron cada
        minuto) confirma el reclamo, sin que el jugador tenga que refrescar la
        pagina a mano. Mismo `cod2-pop` que ya usan los botones de "copiar" del
        sitio (definido en layouts/app.blade.php). --}}
        <div id="claim-confirmed-toast" class="hidden fixed bottom-6 right-6 z-50 max-w-xs rounded-xl border border-emerald-800 bg-emerald-950 text-emerald-200 px-4 py-3 shadow-xl" style="animation: cod2-pop 0.3s ease-out;">
            <p class="font-semibold text-sm">✅ {{ __('¡Reclamo confirmado!') }}</p>
            <p class="text-xs text-emerald-300 mt-1" id="claim-confirmed-name"></p>
            <button type="button" onclick="location.reload()" class="mt-2 text-xs text-emerald-300 hover:text-emerald-100 hover:underline">{{ __('Editar mi perfil →') }}</button>
        </div>

        <script>
            (function () {
                var expiresAt = new Date(@json($siteUser->claim_code_expires_at->toIso8601String())).getTime();
                var totalMs = 15 * 60 * 1000;
                var bar = document.getElementById('claim-progress-bar');

                function tickBar() {
                    var remaining = expiresAt - Date.now();
                    var pct = Math.max(0, Math.min(100, (remaining / totalMs) * 100));
                    bar.style.width = pct + '%';
                    if (remaining <= 0) {
                        clearInterval(barInterval);
                        location.reload();
                    }
                }
                var barInterval = setInterval(tickBar, 1000);
                tickBar();

                function checkStatus() {
                    fetch(@json(route('account.status')), { headers: { 'Accept': 'application/json' } })
                        .then(function (res) { return res.ok ? res.json() : null; })
                        .then(function (data) {
                            if (!data || !data.claimed) return;
                            clearInterval(pollInterval);
                            clearInterval(barInterval);
                            document.getElementById('claim-confirmed-name').textContent = data.player_name || '';
                            document.getElementById('claim-confirmed-toast').classList.remove('hidden');
                            // Se queda visible hasta que el jugador navegue por su cuenta --
                            // antes recargaba solo a los 2.5s, a pedido del dueño se saco
                            // (el aviso desaparecia demasiado rapido). El link de arriba
                            // ("Editar mi perfil") lo lleva al form cuando el quiera.
                        })
                        .catch(function () {});
                }
                var pollInterval = setInterval(checkStatus, 5000);
            })();
        </script>
    @elseif($siteUser->pending_claim_player_id)
        {{-- Codigo vencido sin confirmar --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4 space-y-3">
            <p class="text-sm text-slate-400">{{ __('El código venció sin confirmarse. Volvé al perfil del jugador para generar uno nuevo.') }}</p>
            <a href="{{ route('players.show', $siteUser->pendingClaimPlayer) }}" class="text-cyan-400 hover:underline text-sm">{{ $siteUser->pendingClaimPlayer->last_name_plain }}</a>
        </div>
    @else
        {{-- Sin ningun reclamo todavia --}}
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-4">
            <p class="text-sm text-slate-400">{{ __('Todavía no reclamaste ningún perfil. Buscá tu nombre en el sitio y tocá "¿Sos vos?" en tu página de jugador.') }}</p>
        </div>
    @endif
</div>
@endsection
