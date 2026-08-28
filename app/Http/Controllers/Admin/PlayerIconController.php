<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Player;
use App\Support\PlayerIcon;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Iconos personalizados por jugador (2026-08-28) -- generaliza el chiste
 * hardcodeado de dtN.harek (burro.png al lado de su medalla, guid 1127155189)
 * a cualquier jugador, subido desde acá en vez de vivir pegado al código.
 */
class PlayerIconController extends Controller
{
    public function index()
    {
        $players = Player::with(['aliases' => fn ($q) => $q->orderByDesc('last_seen_at')])
            ->orderByDesc('kills_total')
            ->orderByDesc('last_seen_at')
            ->get();

        return view('admin.players.icons', compact('players'));
    }

    public function store(Request $request, Player $player)
    {
        $request->validate([
            'icon' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        try {
            PlayerIcon::store($player, $request->file('icon'));
        } catch (RuntimeException $e) {
            return back()->withErrors($e->getMessage());
        }

        AdminAction::record('players.icon-upload', "Subió un ícono para \"{$player->last_name_plain}\" (guid {$player->guid})");

        return back()->with('status', "Ícono actualizado para \"{$player->last_name_plain}\".");
    }

    public function destroy(Player $player)
    {
        PlayerIcon::destroy($player);

        AdminAction::record('players.icon-remove', "Quitó el ícono de \"{$player->last_name_plain}\" (guid {$player->guid})");

        return back()->with('status', "Ícono quitado de \"{$player->last_name_plain}\".");
    }
}
