<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PlayerClaimController extends Controller
{
    public function store(Player $player)
    {
        $siteUser = Auth::guard('site')->user();

        if ($siteUser->player_id !== null) {
            return back()->with('error', __('Ya tenés un perfil reclamado. Contactá a un admin si es un error.'));
        }

        if (SiteUser::where('player_id', $player->id)->exists()) {
            return back()->with('error', __('Este perfil ya fue reclamado. Si es un error, contactá a un admin.'));
        }

        $siteUser->update([
            'pending_claim_player_id' => $player->id,
            'claim_code' => strtoupper(Str::random(8)),
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        return redirect()->route('account.show');
    }

    public function cancel()
    {
        Auth::guard('site')->user()->update([
            'pending_claim_player_id' => null,
            'claim_code' => null,
            'claim_code_expires_at' => null,
        ]);

        return redirect()->route('account.show');
    }
}
