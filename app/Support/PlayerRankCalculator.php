<?php

namespace App\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Categoriza a los jugadores de un server en rangos A-E segun un score de
 * 70% K/D + 30% win rate -- cada metrica se convierte a un percentil (0-100)
 * dentro del pool de jugadores calificados antes de combinarse, para que
 * ambas pesen relativo al resto del server y no a una escala arbitraria. Los
 * rangos son quintiles de ese score (A = top 20%, ..., E = bottom 20%).
 *
 * Extraido de SpecialtyController::rango() (2026-08-25) para que el
 * balanceador de equipos de la consola admin (TeamBalancer) reuse el mismo
 * calculo exacto en vez de duplicar la logica de percentiles. Scopeado por
 * temporada (2026-08-27, en conjunto con el plan de "especialidades por
 * temporada") -- Equipos no tiene selector propio, asi que $seasonId nulo
 * (el default) resuelve siempre a Season::current(). rango() en
 * SpecialtyController ahora llama a este mismo metodo en vez de duplicar el
 * calculo, para que los dos consumidores no puedan volver a desincronizarse.
 */
class PlayerRankCalculator
{
    public const MIN_MATCHES = 5;

    public const MIN_KILLS = 20;

    /**
     * Devuelve una colección keyed por guid con: player, kd, hsPct, nadePct,
     * winPct, played, score, rango. Solo incluye jugadores que cumplen
     * MIN_MATCHES/MIN_KILLS -- un guid ausente de la colección significa
     * "todavía no tiene datos suficientes para un rango", no rango E.
     *
     * $seasonId es un id real, el string literal 'all', o null (default --
     * resuelve a Season::current()->id).
     */
    public static function calculateForServer(Server $server, int|string|null $seasonId = null): Collection
    {
        $seasonId ??= Season::current()->id;
        $matchIds = GameMatch::forSeason($seasonId)->pluck('id');

        $sdKills = fn () => Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.server_id', $server->id)
            ->where('rounds.gametype', 'sd')
            ->where('kills.is_suicide', false)
            ->whereIn('kills.match_id', $matchIds);

        $stats = KillAggregator::aggregate($sdKills)
            ->filter(fn ($row) => $row->kills >= self::MIN_KILLS)
            ->keyBy(fn ($row) => $row->player->guid);

        // Mismo proxy de "partidas jugadas/ganadas" que el resto de las
        // paginas de especialidades: sin lista de participantes por partida,
        // un jugador cuenta como presente si aparece como atacante o victima
        // en al menos una baja de esa partida.
        $matches = GameMatch::where('server_id', $server->id)
            ->where('is_backfilled', false)
            ->where('gametype', 'sd')
            ->whereNotNull('ended_at')
            ->whereIn('id', $matchIds)
            ->with('rounds:id,match_id,winner_guids')
            ->get();

        $played = [];
        $won = [];
        foreach ($matches as $match) {
            $kills = Kill::where('match_id', $match->id)->get(['attacker_guid', 'victim_guid']);
            $participantGuids = $kills->pluck('attacker_guid')->merge($kills->pluck('victim_guid'))
                ->filter(fn ($g) => $g && $g !== '0')->unique();

            foreach ($participantGuids as $guid) {
                $played[$guid] = ($played[$guid] ?? 0) + 1;
            }

            $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);
            if ($winningGuids) {
                foreach ($winningGuids as $guid) {
                    $won[$guid] = ($won[$guid] ?? 0) + 1;
                }
            }
        }

        $qualified = collect();
        foreach ($stats as $guid => $stat) {
            $playedCount = $played[$guid] ?? 0;
            if ($playedCount < self::MIN_MATCHES) {
                continue;
            }

            $qualified->push((object) [
                'guid' => $guid,
                'player' => $stat->player,
                'kd' => $stat->deaths > 0 ? round($stat->kills / $stat->deaths, 2) : $stat->kills,
                'hsPct' => $stat->kills > 0 ? round($stat->headshots / $stat->kills * 100, 1) : 0,
                'nadePct' => $stat->kills > 0 ? round($stat->grenade_kills / $stat->kills * 100, 1) : 0,
                'winPct' => round(min($won[$guid] ?? 0, $playedCount) / $playedCount * 100, 1),
                'played' => $playedCount,
            ]);
        }

        $n = $qualified->count();
        if ($n <= 1) {
            return $qualified->keyBy('guid');
        }

        // Percentil 0-100 de cada metrica: ordenar ascendente y ubicar cada
        // jugador por su posicion -- el peor en esa metrica queda en 0, el
        // mejor en 100. Empates comparten el mismo percentil (posicion del
        // primero que aparece con ese valor).
        $percentiles = function (string $field) use ($qualified, $n) {
            $sorted = $qualified->pluck($field)->sort()->values();
            // Las claves de $firstIndexOf son el VALOR de la metrica (ej.
            // 1.29) casteadas a string a proposito -- PHP trunca
            // automaticamente una key float a int, lo que colapsaria a todos
            // los jugadores con K/D entre 1.0 y 1.99 en el mismo percentil
            // sin importar el valor real.
            $firstIndexOf = [];
            foreach ($sorted as $i => $value) {
                $key = (string) $value;
                if (! isset($firstIndexOf[$key])) {
                    $firstIndexOf[$key] = $i;
                }
            }

            return $qualified->map(fn ($row) => round($firstIndexOf[(string) $row->$field] / ($n - 1) * 100, 2));
        };

        $kdPct = $percentiles('kd');
        $winPctPct = $percentiles('winPct');

        $qualified = $qualified->values()->map(function ($row, $i) use ($kdPct, $winPctPct) {
            $row->score = round($kdPct[$i] * 0.7 + $winPctPct[$i] * 0.3, 1);

            return $row;
        })->sortByDesc('score')->values();

        $tiers = ['A', 'B', 'C', 'D', 'E'];
        $qualified = $qualified->map(function ($row, $i) use ($n, $tiers) {
            $quintile = (int) floor($i / ($n / 5));
            $row->rango = $tiers[min($quintile, 4)];

            return $row;
        });

        return $qualified->keyBy('guid');
    }
}
