<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
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

        AdminAction::record('players.clear-ip', "Limpio el pais de \"{$player->last_name_plain}\"");

        return back()->with('status', "Se quitó el país de \"{$player->last_name_plain}\".");
    }

    /**
     * Borra la fila del jugador -- sus kills/chat/bans/demos NO se borran (las
     * FK son nullOnDelete, ver migraciones de esas tablas), quedan en el
     * historial con el guid/nombre tal cual estaban en el momento, igual que ya
     * pasa con los kills de un bot (guid=0, sin player_id). Lo que sí se pierde
     * es lo que solo existe para sostener ESE player_id: alias
     * (cascadeOnDelete) y los acumuladores cacheados
     * (player_map_stats/player_server_stats/player_weapon_picks/
     * player_match_extras, todos cascadeOnDelete) -- ninguno tiene sentido sin
     * el jugador al que pertenecen.
     */
    public function destroy(Player $player)
    {
        $label = "{$player->last_name_plain} (guid {$player->guid}, {$player->kills_total} kills)";

        $player->delete();

        AdminAction::record('players.destroy', "Borro al jugador \"{$label}\"");

        return back()->with('status', "Se borró a \"{$label}\". Sus kills/chat/demos quedan en el historial sin asociar a ningún perfil.");
    }
}
