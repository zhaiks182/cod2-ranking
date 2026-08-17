<?php

namespace App\Http\Controllers;

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
