<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerAlias;
use Illuminate\Http\Request;

/**
 * Buscador público de jugadores (nav de layouts/app.blade.php, 2026-08-31) --
 * busca por nombre actual O cualquier alias historico (mismo criterio que ya
 * usa PlayerMergeController en el admin), asi que encuentra a alguien aunque
 * haya cambiado de nombre desde entonces. Sin fusion/borrado, solo linkea al
 * perfil publico.
 */
class PlayerSearchController extends Controller
{
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $aliasPlayerIds = PlayerAlias::where('name_plain', 'like', "%{$q}%")->pluck('player_id');

        $players = Player::where('last_name_plain', 'like', "%{$q}%")
            ->orWhereIn('id', $aliasPlayerIds)
            ->orderByDesc('kills_total')
            ->limit(8)
            ->get(['id', 'guid', 'last_name_plain', 'icon_path', 'kills_total', 'deaths_total']);

        return response()->json($players->map(fn (Player $p) => [
            'guid' => $p->guid,
            'name' => $p->last_name_plain,
            'icon_url' => $p->icon_url,
            'kills' => $p->kills_total,
            'deaths' => $p->deaths_total,
        ]));
    }
}
