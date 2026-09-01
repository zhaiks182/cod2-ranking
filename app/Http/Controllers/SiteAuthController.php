<?php

namespace App\Http\Controllers;

use App\Models\SiteUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SiteAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('discord')->redirect();
    }

    public function callback()
    {
        $discordUser = Socialite::driver('discord')->user();

        $siteUser = SiteUser::updateOrCreate(
            ['discord_id' => $discordUser->getId()],
            [
                'discord_username' => $discordUser->getNickname() ?? $discordUser->getName(),
                'discord_avatar_url' => $discordUser->getAvatar(),
            ]
        );

        Auth::guard('site')->login($siteUser);

        return redirect()->intended(route('account.show'));
    }

    public function logout(Request $request)
    {
        Auth::guard('site')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
