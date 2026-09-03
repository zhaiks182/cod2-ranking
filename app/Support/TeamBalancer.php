<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Arma 2 equipos sugeridos a partir de los jugadores conectados ahora mismo
 * (RCON status(), ver Cod2RconClient) y su rango (PlayerRankCalculator, el
 * mismo score de 70% K/D + 30% win rate que usa /especialidades/rangos).
 *
 * Solo sugiere -- no mueve a nadie de equipo por RCON (no hay un comando
 * confirmado para forzar el cambio de equipo de un slot; el admin/los
 * jugadores hacen el cambio a mano con "team axis"/"team allies").
 *
 * Snake draft (A,B,B,A,A,B,B,A...) sobre los jugadores ordenados por score
 * descendente -- reparte al mejor y al peor jugador restante de cada
 * "vuelta" de a 2 al mismo equipo, en vez de alternar 1 a 1, para que la
 * suma de score de ambos equipos quede lo mas pareja posible.
 */
class TeamBalancer
{
    /**
     * Minimo de jugadores reales (no bots) conectados para sugerir algo --
     * 2 equipos de al menos 2 jugadores cada uno.
     */
    public const MIN_PLAYERS = 4;

    /**
     * Score neutro (mitad de la escala 0-100 de percentiles) -- red de
     * seguridad solo para cuando $server es null (algunos usos de tests no
     * lo necesitan). Con $server presente, un jugador sin rango todavia
     * usa transitionScoresForServer() en su lugar (2026-09-03), que ya
     * devuelve 50 el mismo para un jugador nuevo sin partidas.
     */
    public const DEFAULT_SCORE = 50.0;

    /**
     * @param  array  $connectedPlayers  status()['players'] de Cod2RconClient
     *                                   (cada uno con slot/score/ping/guid/name/ip)
     * @param  Collection  $ranks  PlayerRankCalculator::calculateForServer($server)
     * @param  \App\Models\Server|null  $server  Solo para resolver la transicion
     *      gradual de rank_score (PlayerRankCalculator::transitionScoresForServer(),
     *      2026-09-03) de un jugador sin rango todavia en la temporada actual --
     *      opcional para no romper otros usos de este metodo que no lo necesiten
     *      (tests, etc.).
     */
    public static function suggest(array $connectedPlayers, Collection $ranks, ?\App\Models\Server $server = null): object
    {
        $bots = 0;
        $pool = collect();

        // Guids conectados sin rango todavía esta temporada -- para pedirle
        // a transitionScoresForServer() el score de transición de todos de
        // una sola vez, antes del loop (2026-09-03, a pedido del dueño).
        // Nunca uno por jugador dentro del loop: mismo motivo que ya evitó
        // el bug de performance de 9f56224 con seasonSeedScore().
        $unrankedGuids = collect($connectedPlayers)
            ->map(fn ($p) => (int) ($p['guid'] ?? 0))
            ->filter(fn ($guid) => $guid !== 0 && ! $ranks->has($guid))
            ->unique()
            ->values()
            ->all();

        $transitionScores = $server ? PlayerRankCalculator::transitionScoresForServer($server, $unrankedGuids) : [];

        foreach ($connectedPlayers as $p) {
            $guid = (int) ($p['guid'] ?? 0);

            // Los bots siempre tienen guid=0 y son indistinguibles entre si
            // (ver CLAUDE.md, "Identidad del jugador") -- no tienen stats ni
            // sentido competitivo, se excluyen del balanceo.
            if ($guid === 0) {
                $bots++;

                continue;
            }

            $rank = $ranks->get($guid);

            $pool->push((object) [
                'guid' => $guid,
                'name' => $p['name'] ?? '(sin nombre)',
                'rango' => $rank->rango ?? null,
                'score' => $rank->score ?? $transitionScores[$guid] ?? self::DEFAULT_SCORE,
            ]);
        }

        if ($pool->count() < self::MIN_PLAYERS) {
            return (object) [
                'enough' => false,
                'eligible' => $pool->count(),
                'bots' => $bots,
                'teamA' => collect(),
                'teamB' => collect(),
            ];
        }

        $sorted = $pool->sortByDesc('score')->values();
        $n = $sorted->count();

        $order = [];
        $reversed = false;
        for ($i = 0; $i < $n; $i += 2) {
            $order = array_merge($order, $reversed ? ['B', 'A'] : ['A', 'B']);
            $reversed = ! $reversed;
        }

        $teamA = collect();
        $teamB = collect();
        foreach ($sorted as $i => $player) {
            if (($order[$i] ?? 'A') === 'A') {
                $teamA->push($player);
            } else {
                $teamB->push($player);
            }
        }

        return (object) [
            'enough' => true,
            'eligible' => $n,
            'bots' => $bots,
            'teamA' => $teamA,
            'teamB' => $teamB,
            'scoreA' => round($teamA->sum('score'), 1),
            'scoreB' => round($teamB->sum('score'), 1),
        ];
    }
}
