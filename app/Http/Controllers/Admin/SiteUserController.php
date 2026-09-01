<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\SiteUser;
use Illuminate\Http\Request;

class SiteUserController extends Controller
{
    public function index()
    {
        $siteUsers = SiteUser::with('player')->orderByDesc('created_at')->get();

        return view('admin.players.discord-accounts', compact('siteUsers'));
    }

    /**
     * Insignia de comunidad (texto libre, ej. "Staff"/"VIP"/"Fundador") --
     * puramente cosmetica, se muestra en el perfil publico del jugador. Nunca
     * toca User::MODULES/permissions, no otorga acceso al panel admin.
     */
    public function updateRole(Request $request, SiteUser $siteUser)
    {
        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        $siteUser->update(['role' => $data['role'] ?: null]);

        AdminAction::record('site-users.update-role', "Cambió el rol de \"{$siteUser->discord_username}\" a \"".($data['role'] ?: 'ninguno')."\"");

        return back()->with('status', 'Rol actualizado.');
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
