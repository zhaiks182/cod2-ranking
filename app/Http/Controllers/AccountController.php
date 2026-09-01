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
            'steam_url' => ['nullable', 'string', 'max:255'],
            'twitch_url' => ['nullable', 'string', 'max:255'],
            'instagram_url' => ['nullable', 'string', 'max:255'],
            'pc_cpu' => ['nullable', 'string', 'max:120'],
            'pc_gpu' => ['nullable', 'string', 'max:120'],
            'pc_ram' => ['nullable', 'string', 'max:120'],
            'pc_peripherals' => ['nullable', 'string', 'max:120'],
        ]);

        $siteUser->update($data);

        return back()->with('status', __('Perfil actualizado.'));
    }
}
