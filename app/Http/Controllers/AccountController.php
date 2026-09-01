<?php

namespace App\Http\Controllers;

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

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:400'],
            // Solo http(s) -- sin esto, un jugador podria guardar un esquema
            // javascript:/data: y el perfil publico (que renderiza estos campos
            // como <a href>) terminaria ejecutando su contenido para cualquier
            // visitante que haga click.
            'steam_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'],
            'twitch_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'],
            'instagram_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'],
            'pc_cpu' => ['nullable', 'string', 'max:120'],
            'pc_gpu' => ['nullable', 'string', 'max:120'],
            'pc_ram' => ['nullable', 'string', 'max:120'],
            'pc_peripherals' => ['nullable', 'string', 'max:120'],
        ]);

        $siteUser->update($data);

        return back()->with('status', __('Perfil actualizado.'));
    }
}
