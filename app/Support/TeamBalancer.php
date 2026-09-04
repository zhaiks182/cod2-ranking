<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
     * Cuanto dura en cache la ultima sugerencia mostrada/notificada por
     * server (2026-09-04, "mantener asignaciones anteriores") -- una
     * sesion de juego tipica, ni tan corto que se pierda entre dos
     * regeneraciones normales del mismo rato, ni tan largo que arrastre
     * una asignacion de un dia completamente distinto.
     */
    private const REMEMBER_HOURS = 6;

    /**
     * @param  array  $connectedPlayers  status()['players'] de Cod2RconClient
     *                                   (cada uno con slot/score/ping/guid/name/ip)
     * @param  Collection  $ranks  PlayerRankCalculator::calculateForServer($server)
     * @param  \App\Models\Server|null  $server  Solo para resolver la transicion
     *      gradual de rank_score (PlayerRankCalculator::transitionScoresForServer(),
     *      2026-09-03) de un jugador sin rango todavia en la temporada actual --
     *      opcional para no romper otros usos de este metodo que no lo necesiten
     *      (tests, etc.).
     * @param  array<int, string>|null  $previousAssignments  guid => 'A'|'B'
     *      (2026-09-04, "mantener asignaciones anteriores") -- si se pasa, los
     *      jugadores conectados que ya aparecen acá NO se recalculan ni se
     *      mueven de equipo; solo los jugadores nuevos (conectados desde la
     *      última sugerencia, ej. alguien que se sumó a mitad de partida) se
     *      reparten entre los dos equipos, cada uno al que tenga el total más
     *      bajo en ese momento. Sin esto (null, el default), se recalcula todo
     *      desde cero como siempre. Ver TeamBalancer::previousAssignments()/
     *      rememberAssignments() para de dónde sale y cómo se guarda esta info.
     */
    public static function suggest(array $connectedPlayers, Collection $ranks, ?\App\Models\Server $server = null, ?array $previousAssignments = null): object
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

        if ($previousAssignments) {
            [$teamA, $teamB] = self::assignPreservingExisting($pool, $previousAssignments);

            return (object) [
                'enough' => true,
                'eligible' => $pool->count(),
                'bots' => $bots,
                'teamA' => $teamA,
                'teamB' => $teamB,
                'scoreA' => round($teamA->sum('score'), 1),
                'scoreB' => round($teamB->sum('score'), 1),
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

    /**
     * Los jugadores ya presentes en $previousAssignments quedan tal cual
     * estaban (mismo equipo, sin recalcular nada) -- solo los que no
     * aparecen ahí (conectados nuevos desde la última sugerencia) se
     * reparten, de mayor a menor score, cada uno al equipo con el total
     * más bajo en ese momento (balance greedy simple, no snake draft --
     * acá ya no se está armando todo desde cero, solo agregando de a poco
     * sin desarmar lo que ya había).
     *
     * @return array{0: Collection, 1: Collection}
     */
    private static function assignPreservingExisting(Collection $pool, array $previousAssignments): array
    {
        $teamA = collect();
        $teamB = collect();
        $new = collect();

        foreach ($pool as $player) {
            $side = $previousAssignments[$player->guid] ?? null;
            if ($side === 'A') {
                $teamA->push($player);
            } elseif ($side === 'B') {
                $teamB->push($player);
            } else {
                $new->push($player);
            }
        }

        foreach ($new->sortByDesc('score')->values() as $player) {
            if ($teamA->sum('score') <= $teamB->sum('score')) {
                $teamA->push($player);
            } else {
                $teamB->push($player);
            }
        }

        return [$teamA, $teamB];
    }

    /**
     * La última sugerencia mostrada/notificada para $server, si hay una
     * reciente (ver REMEMBER_HOURS) -- guid => 'A'|'B'. Null si nunca se
     * generó una en las últimas horas, o si el cache se venció.
     */
    public static function previousAssignments(Server $server): ?array
    {
        return Cache::get(self::cacheKey($server));
    }

    /**
     * Decide si esta corrida debe preservar la última asignación (2026-09-04,
     * seguimiento directo de "mantener asignaciones anteriores" -- el dueño
     * preguntó qué pasaba al cerrar y volver a abrir la consola admin, que
     * recalcula todo en CADA carga de página, no solo cuando se aprieta un
     * botón: sin esto, alguien podía perder lo guardado sin querer, con solo
     * refrescar, antes de llegar a activar el candado).
     *
     * $requestedMantener: `true`/`false` si vino explícito en el
     * request (el toggle 🔒/🔓, o el campo oculto del form de Discord),
     * `null` si no vino nada (una carga de página normal, sin tocar el
     * toggle). Con `null`, el default pasa a ser "preservar si hay algo
     * guardado" -- ya no hace falta acordarse de activar el candado ANTES
     * de volver a entrar, alcanza con que exista una sugerencia reciente.
     */
    public static function shouldPreserve(?bool $requestedMantener, Server $server): bool
    {
        return $requestedMantener ?? (self::previousAssignments($server) !== null);
    }

    /**
     * Guarda la sugerencia actual como "la última" para este server, para
     * que una futura llamada con mantener=true pueda partir de acá. Se
     * llama siempre que se genera una sugerencia nueva (con o sin
     * mantener), no solo en el modo "mantener" -- así la próxima vez que
     * se use ese modo construye sobre la sugerencia real más reciente, no
     * sobre una vieja y ya desactualizada.
     */
    public static function rememberAssignments(Server $server, object $teamBalance): void
    {
        if (! $teamBalance->enough) {
            return;
        }

        $map = [];
        foreach ($teamBalance->teamA as $player) {
            $map[$player->guid] = 'A';
        }
        foreach ($teamBalance->teamB as $player) {
            $map[$player->guid] = 'B';
        }

        Cache::put(self::cacheKey($server), $map, now()->addHours(self::REMEMBER_HOURS));
    }

    private static function cacheKey(Server $server): string
    {
        return "team-balance:last-assignments:{$server->id}";
    }
}
