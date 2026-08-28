<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Player;

/**
 * Modulo independiente a proposito (2026-08-28, a pedido del dueño) -- vivia
 * como un boton mas dentro de /adm_cod2/jugadores/fusionar, pero borrar es una
 * accion mas seria que fusionar (fusionar mueve datos, esto los tira) y merece
 * su propia pantalla con la lista completa de jugadores en vez de depender de
 * buscar primero, mas doble confirmacion en vez de una sola.
 */
class PlayerDeleteController extends Controller
{
    public function index()
    {
        $players = Player::with(['aliases' => fn ($q) => $q->orderByDesc('last_seen_at')])
            ->orderByDesc('last_seen_at')
            ->get();

        return view('admin.players.delete', compact('players'));
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
