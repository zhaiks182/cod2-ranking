<?php

namespace App\Http\Controllers;

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
        $query = Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('kills.attacker_player_id', $player->id)
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
        }

        if ($slug = $request->query('server')) {
            $server = Server::where('slug', $slug)->first();
            if ($server) {
                $query->where('rounds.server_id', $server->id);
            }
        }

        if ($map = $request->query('map')) {
            $query->where('rounds.map', $map);
        }

        if ($from = $request->query('from')) {
            $query->where('kills.occurred_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('to')) {
            $query->where('kills.occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $rows = $query->get(['kills.victim_player_id', 'kills.victim_name']);

        // Group by victim_player_id, not by name string — the same player can have
        // killed under several different chosen nicknames over time (e.g. renamed
        // mid-session), and grouping by raw name string was showing them as separate
        // "victims" instead of merging into one. Bots (guid 0) have no player_id at
        // all, so they fall back to grouping by their color-stripped name.
        $grouped = $rows->groupBy(fn ($k) => $k->victim_player_id ?: 'name:'.(Cod2Colors::stripColors($k->victim_name) ?: $k->victim_name));

        $playerNames = Player::whereIn('id', $grouped->keys()->filter(fn ($k) => is_numeric($k)))
            ->pluck('last_name_plain', 'id');

        $victims = $grouped->map(function ($group, $key) use ($playerNames) {
            $name = is_numeric($key) && isset($playerNames[$key])
                ? $playerNames[$key]
                : (Cod2Colors::stripColors($group->first()->victim_name) ?: $group->first()->victim_name);

            return ['victim' => $name, 'count' => $group->count()];
        })->sortByDesc('count')->values();

        return response()->json($victims);
    }
}
