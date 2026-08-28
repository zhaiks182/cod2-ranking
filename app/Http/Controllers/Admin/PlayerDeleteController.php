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
            ->orderByDesc('kills_total')
            ->orderByDesc('last_seen_at')
            ->get();

        $zeroActivityCount = $players->filter(fn ($p) => $p->kills_total === 0 && $p->deaths_total === 0)->count();

        // Mismo criterio de medallas que /ranking (top 3 por kills) -- se calcula
        // acá y no por posición en la tabla porque la tabla es ordenable por el
        // usuario (kills o deaths, asc/desc) y la medalla tiene que seguir
        // representando "top 3 por kills" sin importar cómo esté ordenada la
        // vista en un momento dado. Nadie con 0 kills recibe medalla.
        $medalPlayerIds = $players->filter(fn ($p) => $p->kills_total > 0)
            ->sortByDesc('kills_total')->take(3)->pluck('id')->values();

        return view('admin.players.delete', compact('players', 'zeroActivityCount', 'medalPlayerIds'));
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

    /**
     * Borrado en masa de los perfiles "fantasma" -- 0 kills Y 0 deaths, nunca
     * generaron ningun evento de juego real (ver CLAUDE.md, "Bug real: filas
     * fantasma en players por guid corrupto en transito", 2026-08-28). No hay
     * nada real que perder: mismo delete() de siempre, solo que en bloque.
     * Se lee la lista ANTES de borrar (para poder auditar quienes eran, ya
     * que las filas van a desaparecer) y se borra con un solo whereIn para no
     * hacer N queries.
     */
    public function destroyZeroActivity()
    {
        $players = Player::where('kills_total', 0)->where('deaths_total', 0)->get(['id', 'last_name_plain', 'guid']);

        if ($players->isEmpty()) {
            return back()->with('status', 'No hay jugadores con 0 kills y 0 deaths para borrar.');
        }

        $count = $players->count();
        $label = $players->map(fn ($p) => "{$p->last_name_plain} (guid {$p->guid})")->implode(', ');

        Player::whereIn('id', $players->pluck('id'))->delete();

        AdminAction::record('players.destroy-bulk-zero-activity', "Borro en masa {$count} jugadores con 0 kills/0 deaths: {$label}");

        return back()->with('status', "Se borraron {$count} jugadores con 0 kills y 0 deaths.");
    }
}
