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
        // Reused by the Headshots/Granadas columns of /ranking (a jugador pidio que
        // esos valores fueran clickeables "igual que las kills", 2026-08-28) --
        // ?type=headshot|grenade acota el mismo popover a solo esas bajas, en vez de
        // ser un endpoint nuevo. La reverse-count ("te mato de vuelta") tambien
        // respeta el mismo type: sin esto, ver "a quien headshoteo" pero la cara-a-
        // cara mostrando CUALQUIER baja de vuelta (no solo headshots) seria confuso.
        $type = in_array($request->query('type'), ['headshot', 'grenade'], true) ? $request->query('type') : null;

        // ?weapon= (2026-08-29, a pedido del dueño -- las columnas Kills/Headshots de
        // la tabla "Armas" del perfil de jugador tenian que ser clickeables mostrando
        // SOLO las bajas con esa arma puntual, no todas las del jugador).
        $weapon = $request->query('weapon');

        $applyScope = function ($query) use ($request, $type, $weapon) {
            $query->join('rounds', 'rounds.id', '=', 'kills.round_id')
                ->where('kills.is_suicide', false)
                // Teammates killed by mistake have their own breakdown under the red
                // "Fuego amigo" indicator — keep them out of the regular kills list so it
                // only shows real opponents.
                ->where('kills.is_teamkill', false)
                ->when($type === 'headshot', fn ($q) => $q->where('kills.is_headshot', true))
                ->when($type === 'grenade', fn ($q) => $q->where('kills.is_grenade', true))
                ->when($weapon, fn ($q) => $q->where('kills.weapon', $weapon));

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

        // ?direction=deaths (2026-08-29, a pedido del dueño -- la card "Muertes" del
        // perfil de jugador no tenia ningun detalle al hacer click, a diferencia de
        // Kills/Headshots/Granadas). Mismo query, atacante/victima invertidos: en vez
        // de "a quien mato $player" (kills), "quien mato a $player" (deaths) -- y la
        // cara-a-cara se invierte junto con el resto (ver mas abajo).
        $direction = $request->query('direction') === 'deaths' ? 'deaths' : 'kills';
        $playerCol = $direction === 'deaths' ? 'kills.victim_player_id' : 'kills.attacker_player_id';
        $otherIdCol = $direction === 'deaths' ? 'kills.attacker_player_id' : 'kills.victim_player_id';
        $otherNameCol = $direction === 'deaths' ? 'kills.attacker_name' : 'kills.victim_name';

        $rows = $applyScope(Kill::query()->where($playerCol, $player->id))
            ->get([$otherIdCol.' as other_player_id', $otherNameCol.' as other_name']);

        // Group by the other player's id, not by name string — the same player can
        // have played under several different chosen nicknames over time (e.g.
        // renamed mid-session), and grouping by raw name string was showing them as
        // separate entries instead of merging into one. Bots (guid 0) have no
        // player_id at all, so they fall back to grouping by their color-stripped name.
        $grouped = $rows->groupBy(fn ($k) => $k->other_player_id ?: 'name:'.(Cod2Colors::stripColors($k->other_name) ?: $k->other_name));

        $playerNames = Player::whereIn('id', $grouped->keys()->filter(fn ($k) => is_numeric($k)))
            ->pluck('last_name_plain', 'id');

        // "Cara a cara": el mismo enfrentamiento en la direccion contraria (si estamos
        // mostrando "a quien mato $player", esto es "quien mato a $player de vuelta",
        // y viceversa para direction=deaths) -- mismo alcance de filtros. Se trae de
        // una sola consulta batcheada (no una por fila) y el frontend la revela recien
        // al hacer click en la fila correspondiente del popover, sin pedir nada mas
        // al server (ver openDetailsPopover() en layouts/app.blade.php).
        $otherIds = $grouped->keys()->filter(fn ($k) => is_numeric($k))->values();
        $reverseCounts = $applyScope(
            Kill::query()->where($otherIdCol, $player->id)->whereIn($playerCol, $otherIds)
        )->selectRaw($playerCol.' as opponent_id, count(*) as total')
            ->groupBy($playerCol)
            ->pluck('total', 'opponent_id');

        $victims = $grouped->map(function ($group, $key) use ($playerNames, $reverseCounts) {
            $name = is_numeric($key) && isset($playerNames[$key])
                ? $playerNames[$key]
                : (Cod2Colors::stripColors($group->first()->other_name) ?: $group->first()->other_name);

            return [
                'victim' => $name,
                'count' => $group->count(),
                'reverse' => is_numeric($key) ? (int) ($reverseCounts[$key] ?? 0) : null,
            ];
        })->sortByDesc('count')->values();

        return response()->json($victims);
    }
}
