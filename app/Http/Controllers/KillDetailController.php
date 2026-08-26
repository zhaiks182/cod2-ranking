<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Server;
use App\Support\Cod2Colors;
use Carbon\Carbon;
use Illuminate\Http\Request;

class KillDetailController extends Controller
{
    /**
     * Returns the players behind a player's kill count, grouped by victim with a
     * repeat count — same scope params as TeamkillController (server/map/match/date).
     */
    public function index(Request $request, Player $player)
    {
        // Reused for both directions below (this player's kills, and — per victim —
        // how many times that victim killed this player back) so the two queries stay
        // scoped identically (server/map/match/date), just with attacker/victim swapped.
        $applyScope = function ($query) use ($request) {
            $query->join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('kills.is_suicide', false)
                // Teammates killed by mistake have their own breakdown under the red
                // "Fuego amigo" indicator — keep them out of the regular kills list so it
                // only shows real opponents.
                ->where('kills.is_teamkill', false);

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

            return $query;
        };

        $rows = $applyScope(Kill::query()->where('kills.attacker_player_id', $player->id))
            ->get(['kills.victim_player_id', 'kills.victim_name']);

        // Group by victim_player_id, not by name string — the same player can have
        // killed under several different chosen nicknames over time (e.g. renamed
        // mid-session), and grouping by raw name string was showing them as separate
        // "victims" instead of merging into one. Bots (guid 0) have no player_id at
        // all, so they fall back to grouping by their color-stripped name.
        $grouped = $rows->groupBy(fn ($k) => $k->victim_player_id ?: 'name:'.(Cod2Colors::stripColors($k->victim_name) ?: $k->victim_name));

        $playerNames = Player::whereIn('id', $grouped->keys()->filter(fn ($k) => is_numeric($k)))
            ->pluck('last_name_plain', 'id');

        // "Cara a cara": cuantas veces cada victima (que sea un jugador real, no bot)
        // mato de vuelta a $player, con el mismo alcance de filtros. Se trae de una
        // sola consulta batcheada (no una por victima) y el frontend la revela recien
        // al hacer click en la fila correspondiente del popover, sin pedir nada mas
        // al server (ver openDetailsPopover() en layouts/app.blade.php).
        $victimIds = $grouped->keys()->filter(fn ($k) => is_numeric($k))->values();
        $reverseCounts = $applyScope(
            Kill::query()->where('kills.victim_player_id', $player->id)->whereIn('kills.attacker_player_id', $victimIds)
        )->selectRaw('kills.attacker_player_id, count(*) as total')
            ->groupBy('kills.attacker_player_id')
            ->pluck('total', 'attacker_player_id');

        $victims = $grouped->map(function ($group, $key) use ($playerNames, $reverseCounts) {
            $name = is_numeric($key) && isset($playerNames[$key])
                ? $playerNames[$key]
                : (Cod2Colors::stripColors($group->first()->victim_name) ?: $group->first()->victim_name);

            return [
                'victim' => $name,
                'count' => $group->count(),
                'reverse' => is_numeric($key) ? (int) ($reverseCounts[$key] ?? 0) : null,
            ];
        })->sortByDesc('count')->values();

        return response()->json($victims);
    }
}
