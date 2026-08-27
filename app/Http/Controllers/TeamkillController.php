<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Server;
use App\Support\Cod2Colors;
use App\Support\MapCatalog;
use App\Support\WeaponCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TeamkillController extends Controller
{
    /**
     * Returns the individual teamkill events behind a player's "(-N)" indicator, scoped
     * by whichever filters the calling view is already using (server/map/match/date
     * range) — same query params as the leaderboard/match/player-profile pages, so the
     * popover always matches whatever number is on screen.
     */
    public function index(Request $request, Player $player)
    {
        $query = Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_teamkill', true);

        if ($matchId = $request->query('match')) {
            // A single match's own page shows what actually happened in it, regardless
            // of gametype — the SD-only rule below is for the aggregate ranking.
            $query->where('kills.match_id', $matchId);
        } else {
            $query->where('rounds.gametype', 'sd');

            // Sin match explicito, el resultado tiene que respetar la misma temporada que el
            // numero que el usuario cliqueo en pantalla (ranking o perfil de jugador) -- sin
            // esto el popover mezcla todas las temporadas aunque el numero en pantalla este
            // scopeado a una sola. Sin parametro 'season' en la URL (llamadores viejos que no
            // lo mandan), se comporta como 'all' -- de paso corrige un bug latente que ya
            // tenia esta clase antes de las temporadas: nunca excluia partidas abandonadas sin
            // resultado real (GameMatch::abandonedWithoutConclusion(), ya usado en
            // GameMatch::scopeForSeason()) de este popover.
            $seasonParam = $request->query('season');
            $seasonScope = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : 'all');
            $query->whereIn('kills.match_id', GameMatch::forSeason($seasonScope)->pluck('id'));
        }

        if ($slug = $request->query('server')) {
            $server = Server::where('slug', $slug)->first();
            if ($server) {
                $query->where('rounds.server_id', $server->id);
            }
        }

        if ($map = $request->query('map')) {
            // Variantes del mismo mapa real (mp_dawnville_fix + mp_dawnville_sun) se
            // muestran combinadas en "Mejores mapas" (ver MapCatalog::mergeVariants),
            // asi que el filtro tiene que aceptar los codigos separados por coma para
            // que el detalle no se quede con solo la mitad de las bajas.
            $query->whereIn('rounds.map', explode(',', $map));
        }

        if ($from = $request->query('from')) {
            $query->where('kills.occurred_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('to')) {
            $query->where('kills.occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $kills = $query->orderByDesc('kills.occurred_at')
            ->limit(50)
            ->get(['kills.victim_name', 'kills.weapon', 'kills.occurred_at', 'rounds.map']);

        return response()->json($kills->map(fn ($k) => [
            'victim' => Cod2Colors::stripColors($k->victim_name) ?: $k->victim_name,
            'weapon' => WeaponCatalog::label($k->weapon),
            'map' => MapCatalog::mapLabel($k->map),
            'occurred_at' => optional($k->occurred_at)->diffForHumans(),
        ]));
    }
}
