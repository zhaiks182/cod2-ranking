<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\SiteUserAvatar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function show()
    {
        $siteUser = Auth::guard('site')->user()->load('player', 'pendingClaimPlayer');

        return view('account.show', compact('siteUser'));
    }

    public function update(Request $request)
    {
        $siteUser = Auth::guard('site')->user();

        abort_unless($siteUser->player_id !== null, 403);

        $urlRule = ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'];

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:400'],
            'clan_tag' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'language' => ['nullable', 'string', 'in:'.implode(',', SetLocale::SUPPORTED)],
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
            'show_on_ranking' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        // Checkbox ausente en el POST = desmarcado, no "no cambiar" -- HTML no
        // manda nada para un checkbox sin marcar, hay que resolverlo a false
        // explicito en vez de dejar el valor anterior sin tocar.
        $data['show_on_ranking'] = $request->boolean('show_on_ranking');

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
