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
     * Score neutro (mitad de la escala 0-100 de percentiles) para un jugador
     * conectado que todavia no califica para un rango (pocas partidas/bajas
     * en este server) -- lo ubica en el medio del draft en vez de sesgar el
     * balance asumiendo que es el mejor o el peor.
     */
    public const DEFAULT_SCORE = 50.0;

    /**
     * @param  array  $connectedPlayers  status()['players'] de Cod2RconClient
     *                                   (cada uno con slot/score/ping/guid/name/ip)
     * @param  Collection  $ranks  PlayerRankCalculator::calculateForServer($server)
     * @param  \App\Models\Server|null  $server  Solo para resolver el MMR semilla
     *      (PlayerRankCalculator::seasonSeedScore(), 2026-09-02) de un jugador sin
     *      rango todavia en la temporada actual -- opcional para no romper otros
     *      usos de este metodo que no lo necesiten (tests, etc.).
     */
    public static function suggest(array $connectedPlayers, Collection $ranks, ?\App\Models\Server $server = null): object
    {
        $bots = 0;
        $pool = collect();

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

            // Sin rango todavia esta temporada -- antes de caer al score
            // neutro, intenta el MMR semilla de la temporada anterior
            // (2026-09-02, a pedido del dueño). Devuelve null (sin efecto)
            // si no hay temporada anterior o el jugador no calificaba ahi.
            $seedScore = ! $rank && $server ? PlayerRankCalculator::seasonSeedScore($server, $guid) : null;

            $pool->push((object) [
                'guid' => $guid,
                'name' => $p['name'] ?? '(sin nombre)',
                'rango' => $rank->rango ?? null,
                'score' => $rank->score ?? $seedScore ?? self::DEFAULT_SCORE,
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
