<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Services\GeoIp;

class PlayerController extends Controller
{
    /**
     * Same grouping as the public /paises page (SpecialtyController::countries()),
     * but flat and admin-only — lets the admin spot a player whose IP/country looks
     * wrong (VPN, or a garbled duplicate account like the old "ZHAIKS"/"Prime"
     * truncated-name incidents, see CLAUDE.md) and clear it.
     */
    public function index()
    {
        $players = Player::whereNotNull('ip')->orderByDesc('last_seen_at')
            ->get(['id', 'guid', 'last_name', 'ip', 'kills_total', 'last_seen_at']);

        $rows = $players->map(function ($player) {
            $country = GeoIp::countryFor($player->ip);

            return (object) [
                'player' => $player,
                'country' => $country,
            ];
        })->sortBy(fn ($row) => $row->country['name'] ?? 'zzz')->values();

        return view('admin.players.index', compact('rows'));
    }

    public function clearIp(Player $player)
    {
        $player->update(['ip' => null]);

        return back()->with('status', "Se quitó el país de \"{$player->last_name_plain}\".");
    }
}
