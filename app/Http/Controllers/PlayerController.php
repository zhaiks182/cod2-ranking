<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerWeaponPick;
use App\Models\Season;
use App\Support\KillAggregator;
use App\Support\MapCatalog;
use App\Support\PlaytimeCalculator;
use App\Support\WinRateCalculator;
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

        // Horas jugadas (2026-08-29, a pedido del dueño -- reemplaza "dias jugados"
        // del ranking en todos lados, mismo PlaytimeCalculator). Sin scopear por
        // server: el perfil de jugador es global (players no son por server, ver
        // "Multi-servidor" en el CLAUDE.md del repo), a diferencia de /ranking.
        $secondsPlayed = PlaytimeCalculator::secondsByPlayer(null, $matchIds);
        $hoursPlayed = round(($secondsPlayed[$player->id] ?? 0) / 3600, 1);

        // Win rate general (2026-08-29, a pedido del dueño -- reemplaza la idea
        // original de una columna de winrate por mapa en "Mejores mapas" por un
        // solo numero agregado, mas simple de leer de un vistazo).
        $winRate = WinRateCalculator::forPlayer($player, $matchIds);

        // Desempeño general por mapa (2026-08-29, a pedido del dueño -- unifica
        // "Mejores mapas" (kills/muertes, de $player->mapStats de arriba) y "Mapas
        // ganados" (jugadas/ganadas/win rate, de WinRateCalculator::byMapForPlayer)
        // en una sola tabla, en vez de dos secciones separadas mostrando el mismo
        // mapa dos veces. Se itera $player->mapStats (la lista mas completa -- cuenta
        // CUALQUIER partida con al menos un kill/muerte del jugador) y se le suma el
        // dato de victorias por mapa cuando existe (solo partidas con ganador
        // determinable, ver WinRateCalculator) -- un mapa sin ganador determinable
        // en ninguna partida simplemente queda en 0 jugadas/0 ganadas, no desaparece.
        $winsByMap = WinRateCalculator::byMapForPlayer($player, $matchIds)->keyBy('map');
        $mapPerformance = $player->mapStats->map(function ($stat) use ($winsByMap) {
            $win = $winsByMap->get($stat->map);

            return (object) [
                'map' => $stat->map,
                'map_codes' => $stat->map_codes,
                'server' => $stat->server,
                'kills' => $stat->kills,
                'deaths' => $stat->deaths,
                'teamkills' => $stat->teamkills,
                'played' => $win->played ?? 0,
                'wins' => $win->wins ?? 0,
                'rate' => $win->rate ?? 0.0,
            ];
        });

        // Historial de partidas (2026-08-29, seguimiento directo del punto
        // anterior -- el dueño pregunto si "Mapas ganados" tambien mostraba el
        // historial partida por partida, no solo los totales agregados).
        $matchHistory = WinRateCalculator::matchHistoryForPlayer($player, $matchIds);

        // Reemplaza "Últimas bajas"/"Últimas muertes" (2026-08-29, a pedido del
        // dueño relayando feedback real de un jugador: esa lista cronologica era
        // "poco relevante", pedia algo mas util). Desglose COMPLETO de armas (no
        // solo la favorita de mas arriba).
        $weaponBreakdown = $baseKillQuery()
            ->where('kills.attacker_player_id', $player->id)
            ->where('kills.is_suicide', false)
            ->selectRaw('kills.weapon, count(*) as kills, sum(kills.is_headshot) as headshots')
            ->groupBy('kills.weapon')
            ->orderByDesc('kills')
            ->get();

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

        return view('players.show', compact('player', 'seasons', 'seasonId', 'hoursPlayed', 'winRate', 'mapPerformance', 'matchHistory', 'weaponBreakdown', 'favoriteWeapon', 'teamkillCount', 'mostEquippedWeapon'));
    }
}
