<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Kill;
use App\Support\CountryCatalog;
use App\Support\SiteUserAvatar;
use App\Support\WeaponCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function show()
    {
        $siteUser = Auth::guard('site')->user()->load('player', 'pendingClaimPlayer');

        // "Rol preferido" (2026-09-01, antes texto libre) -- se elige entre
        // las armas con las que el jugador REALMENTE tiene bajas, no
        // cualquier cosa tipeada a mano. Ordenadas por mas usada primero.
        $usedWeapons = collect();
        if ($siteUser->player_id) {
            $usedWeapons = Kill::where('attacker_player_id', $siteUser->player_id)
                ->where('is_suicide', false)
                ->selectRaw('weapon, count(*) as kills')
                ->groupBy('weapon')
                ->orderByDesc('kills')
                ->pluck('weapon')
                ->map(fn ($code) => ['code' => $code, 'label' => WeaponCatalog::label($code)]);
        }

        return view('account.show', compact('siteUser', 'usedWeapons'));
    }

    public function update(Request $request)
    {
        $siteUser = Auth::guard('site')->user();

        abort_unless($siteUser->player_id !== null, 403);

        $urlRule = ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'];

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:400'],
            'clan_tag' => ['nullable', 'string', 'max:20'],
            // Elegido de una lista (2026-09-01, antes texto libre) -- asi
            // GeoIp::flagIconHtml() siempre tiene un codigo real con el que
            // dibujar una bandera de verdad.
            'country' => ['nullable', 'string', 'in:'.implode(',', array_keys(CountryCatalog::OPTIONS))],
            'language' => ['nullable', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
            // Guarda el CODIGO del arma (ej. "weapon_mp44"), no el texto libre
            // que tenia antes -- se resuelve a nombre bonito con
            // WeaponCatalog::label() donde se muestra. Sin validar contra una
            // lista fija: el selector en el form ya limita a las armas
            // reales del jugador, y forzar coincidencia exacta aca
            // duplicaria esa misma query sin necesidad real.
            'preferred_role' => ['nullable', 'string', 'max:40'],
            // Solo http(s) -- sin esto, un jugador podria guardar un esquema
            // javascript:/data: y el perfil publico (que renderiza estos campos
            // como <a href>) terminaria ejecutando su contenido para cualquier
            // visitante que haga click.
            'steam_url' => $urlRule,
            'twitch_url' => $urlRule,
            'instagram_url' => $urlRule,
            'youtube_url' => $urlRule,
            'twitter_url' => $urlRule,
            'website_url' => $urlRule,
            'pc_cpu' => ['nullable', 'string', 'max:120'],
            'pc_gpu' => ['nullable', 'string', 'max:120'],
            'pc_ram' => ['nullable', 'string', 'max:120'],
            'pc_peripherals' => ['nullable', 'string', 'max:120'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        if ($request->hasFile('avatar')) {
            SiteUserAvatar::store($siteUser, $request->file('avatar'));
        }
        unset($data['avatar']);

        $siteUser->update($data);

        return back()->with('status', __('Perfil actualizado.'));
    }

    /**
     * Consultado por JS desde /mi-cuenta mientras hay un reclamo pendiente,
     * para avisar apenas players:check-claims (cron cada minuto) lo confirme
     * sin que el jugador tenga que refrescar la pagina a mano.
     */
    public function status()
    {
        $siteUser = Auth::guard('site')->user();

        return response()->json([
            'claimed' => $siteUser->player_id !== null,
            'player_name' => $siteUser->player?->last_name_plain,
        ]);
    }
}
