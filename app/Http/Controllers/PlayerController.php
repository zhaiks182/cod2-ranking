<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerWeaponPick;
use App\Models\Season;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerController extends Controller
{
    public function show(Request $request, Player $player)
    {
        $player->load(['aliases' => fn ($q) => $q->orderByDesc('last_seen_at')]);

        $seasons = Season::orderByDesc('started_at')->get();
        $seasonParam = $request->query('season');
        $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        $baseKillQuery = fn () => Kill::query()
            ->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.gametype', 'sd')
            ->whereIn('kills.match_id', $matchIds);

        // Numeros principales — antes players.kills_total/etc (de por vida), ahora
        // calculados al vuelo scopeados a la temporada elegida. aggregate() ya trae
        // kills/deaths/headshots/grenade_kills/teamkills/bash agrupado por jugador;
        // se scopea la query base a este jugador solo (attacker O victim) antes de
        // llamarlo, para no calcular el ranking completo del server solo para leer
        // una fila.
        $totals = KillAggregator::aggregate(fn () => $baseKillQuery()
            ->where(fn ($q) => $q->where('kills.attacker_player_id', $player->id)->orWhere('kills.victim_player_id', $player->id))
        )->firstWhere('player.id', $player->id);

        // Overriding these in-memory (not saved) lets the existing view/accessors
        // (Player::getKdRatioAttribute()/getHeadshotRateAttribute(), which read
        // $this->kills_total/deaths_total/headshots_total) work unchanged against
        // the season-scoped numbers instead of the lifetime columns.
        $player->kills_total = $totals->kills ?? 0;
        $player->deaths_total = $totals->deaths ?? 0;
        $player->headshots_total = $totals->headshots ?? 0;
        $player->grenade_kills_total = $totals->grenade_kills ?? 0;

        $mapStats = KillAggregator::aggregateByMap($baseKillQuery, $player->id)
            ->filter(fn ($s) => $s->kills > 0 || $s->deaths > 0);
        $player->setRelation('mapStats', MapCatalog::mergeVariants($mapStats));

        $recentKills = $player->kills()->where('is_suicide', false)->whereIn('match_id', $matchIds)
            ->with('round', 'victim')->latest('id')->limit(15)->get();
        $recentDeaths = $player->deaths()->whereIn('match_id', $matchIds)
            ->with('round', 'attacker')->latest('id')->limit(15)->get();

        // Scoped to SD like the rest of the ranking (kills_total etc.) — a DM/HQ/CTF
        // kill shouldn't skew "favorite weapon" or the team-kill count.
        $favoriteWeapon = $baseKillQuery()
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_suicide', false)
            ->select('kills.weapon', DB::raw('count(*) as uses'))
            ->groupBy('kills.weapon')
            ->orderByDesc('uses')
            ->first();

        // Included in kills_total (zPAM's own Score counts it too, confirmed against a
        // real match) — this is just for visibility, not a separate/excluded number.
        $teamkillCount = $baseKillQuery()
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_teamkill', true)
            ->count();

        $mostEquippedWeapon = PlayerWeaponPick::where('player_id', $player->id)
            ->when($seasonId !== 'all', fn ($q) => $q->where('season_id', $seasonId))
            ->orderByDesc('picks')
            ->first();

        return view('players.show', compact('player', 'seasons', 'seasonId', 'recentKills', 'recentDeaths', 'favoriteWeapon', 'teamkillCount', 'mostEquippedWeapon'));
    }
}
