<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\SiteUser;

class SiteUserController extends Controller
{
    public function index()
    {
        $siteUsers = SiteUser::with('player')->orderByDesc('created_at')->get();

        return view('admin.players.discord-accounts', compact('siteUsers'));
    }

    public function unlink(SiteUser $siteUser)
    {
        $label = "{$siteUser->discord_username} (discord_id {$siteUser->discord_id})";
        $playerLabel = $siteUser->player?->last_name_plain ?? 'ninguno';

        $siteUser->update(['player_id' => null]);

        AdminAction::record('site-users.unlink', "Desvinculó la cuenta de Discord \"{$label}\" del jugador \"{$playerLabel}\"");

        return back()->with('status', 'Cuenta desvinculada.');
    }
}
