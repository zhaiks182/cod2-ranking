<?php

namespace App\Http\Controllers;

use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerWeaponPick;
use App\Support\MapCatalog;
use Illuminate\Support\Facades\DB;

class PlayerController extends Controller
{
    public function show(Player $player)
    {
        $player->load([
            'aliases' => fn ($q) => $q->orderByDesc('last_seen_at'),
            'mapStats' => fn ($q) => $q->with('server')
                ->where(fn ($q) => $q->where('kills', '>', 0)->orWhere('deaths', '>', 0))
                ->orderByDesc('kills'),
        ]);

        $player->setRelation('mapStats', MapCatalog::mergeVariants($player->mapStats));

        $recentKills = $player->kills()->where('is_suicide', false)->with('round', 'victim')->latest('id')->limit(15)->get();
        $recentDeaths = $player->deaths()->with('round', 'attacker')->latest('id')->limit(15)->get();

        // Scoped to SD like the rest of the ranking (kills_total etc.) — a DM/HQ/CTF
        // kill shouldn't skew "favorite weapon" or the team-kill count shown next to
        // an SD-only kills_total.
        $favoriteWeapon = Kill::join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_suicide', false)
            ->where('rounds.gametype', 'sd')
            ->select('kills.weapon', DB::raw('count(*) as uses'))
            ->groupBy('kills.weapon')
            ->orderByDesc('uses')
            ->first();

        // Included in kills_total (zPAM's own Score counts it too, confirmed against a
        // real match) — this is just for visibility, not a separate/excluded number.
        $teamkillCount = Kill::join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_teamkill', true)
            ->where('rounds.gametype', 'sd')
            ->count();

        // "Reaches for" (equips/switches to), not "kills with" — a different signal
        // from $favoriteWeapon above, which is derived from Weapon; pickups, not Kill;.
        $mostEquippedWeapon = PlayerWeaponPick::where('player_id', $player->id)->orderByDesc('picks')->first();

        return view('players.show', compact('player', 'recentKills', 'recentDeaths', 'favoriteWeapon', 'teamkillCount', 'mostEquippedWeapon'));
    }
}
