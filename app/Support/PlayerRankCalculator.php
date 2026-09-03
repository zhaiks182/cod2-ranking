<?php

namespace App\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Categoriza a los jugadores de un server en rangos S/A/B/C/D segun un score
 * de 50% win rate + 30% K/D + 20% "Impacto" (ImpactScoreCalculator: bombas,
 * primera sangre, multi-kills, clutches -- 2026-09-02, a pedido del dueño,
 * para incentivar jugar a ganar la ronda en vez de acumular bajas sueltas
 * sin mas). Cada metrica se convierte a un percentil (0-100) dentro del pool
 * de jugadores calificados antes de combinarse, para que las tres pesen
 * relativo al resto del server y no a una escala arbitraria.
 *
 * Los rangos ya NO son quintiles iguales -- son una distribucion normal
 * seccionada por la posicion percentil en la tabla ordenada (no por el valor
 * del score en si): P_tabla = (1 - (posicion-1)/(N-1)) x 100, con cortes en
 * 95/75/25/5 (S=top 5%, A=siguiente 20%, B=el 50% del medio, C=siguiente
 * 20%, D=el 5% de abajo) -- ver percentileTiers() mas abajo y la pagina
 * publica /como-funciona-el-rango para la explicacion completa.
 *
 * Extraido de SpecialtyController::rango() (2026-08-25) para que el
 * balanceador de equipos de la consola admin (TeamBalancer) reuse el mismo
 * calculo exacto en vez de duplicar la logica de percentiles. Scopeado por
 * temporada (2026-08-27, en conjunto con el plan de "especialidades por
 * temporada") -- Equipos no tiene selector propio, asi que $seasonId nulo
 * (el default) resuelve siempre a Season::current(). rango() en
 * SpecialtyController ahora llama a este mismo metodo en vez de duplicar el
 * calculo, para que los dos consumidores no puedan volver a desincronizarse.
 *
 * MIN_MATCHES subido de 5 a 10 y el minimo de bajas (antes 20) eliminado del
 * todo (2026-08-27, a pedido del dueño) -- un jugador con pocas bajas pero
 * muchas partidas ahora sí califica para un rango. Bajado de 10 a 9 al dia
 * siguiente (2026-08-28, a pedido del dueño, relayando feedback de
 * jugadores -- juegan ~3 partidas por dia, 9 deja calificar un dia antes).
 */
class PlayerRankCalculator
{
    public const MIN_MATCHES = 9;

    /**
     * Cortes de la distribucion normal seccionada, en percentil de posicion
     * dentro de la tabla (100 = el mejor puesto). S/A/B/C/D = 5/20/50/20/5%.
     */
    private const TIER_CUTOFFS = ['S' => 95, 'A' => 75, 'B' => 25, 'C' => 5, 'D' => 0];

    /**
     * Devuelve una colección keyed por guid con: player, kd, hsPct, nadePct,
     * winPct, played, score, rango. Solo incluye jugadores que cumplen
     * MIN_MATCHES -- un guid ausente de la colección significa "todavía no
     * tiene datos suficientes para un rango", no rango E.
     *
     * $seasonId es un id real, el string literal 'all', o null (default --
     * resuelve a Season::current()->id).
     */
    /**
     * Cuantas partidas distintas jugo cada player_id en este server+scope --
     * mismo proxy de "participo" que calculateForServer() (aparecio como
     * atacante o victima en al menos una baja SD de esa partida), extraido
     * aparte (2026-09-02, a pedido del dueño) para que otras paginas de
     * /especialidades (no solo /rango) puedan exigir un minimo de partidas
     * sin duplicar la logica de "que cuenta como jugada". Caso real que lo
     * motivo: un jugador con muy pocas partidas aparecio 2do en K/D
     * (/eficiencia), que solo exigia un minimo de BAJAS (20), nunca de
     * partidas -- una muestra chica infla facil un ratio.
     *
     * @return array<int, int> player_id => cantidad de partidas
     */
    public static function matchesPlayedByPlayer(int $serverId, Collection $matchIds): array
    {
        $rows = Kill::query()->join('rounds', 'rounds.id', '=', 'kills.round_id')
            ->where('rounds.server_id', $serverId)
            ->where('rounds.gametype', 'sd')
            ->whereIn('kills.match_id', $matchIds)
            ->select('kills.match_id', 'kills.attacker_player_id', 'kills.victim_player_id')
            ->get();

        $matchesByPlayer = [];
        foreach ($rows as $row) {
            foreach ([$row->attacker_player_id, $row->victim_player_id] as $playerId) {
                if ($playerId) {
                    $matchesByPlayer[$playerId][$row->match_id] = true;
                }
            }
        }

        return array_map('count', $matchesByPlayer);
    }

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
            ->keyBy(fn ($row) => $row->player->guid);

        // Mismo proxy de "partidas jugadas/ganadas" que el resto de las
        // paginas de especialidades: sin lista de participantes por partida,
        // un jugador cuenta como presente si aparece como atacante o victima
        // en al menos una baja de esa partida.
        //
        // Se lleva por attacker_player_id/victim_player_id (la FK), no por el
        // guid crudo de la kill -- PlayerMerger::merge() repunta esa FK al
        // fusionar dos jugadores, pero a proposito nunca reescribe el guid
        // historico de kills/rounds.winner_guids (ver CLAUDE.md). Llevar el
        // conteo por guid crudo fragmentaba las partidas de un jugador
        // fusionado entre su guid viejo (ya sin fila en `players`) y el
        // actual, dejandolo sin rango pese a tener de sobra MIN_MATCHES
        // combinadas (bug real 2026-08-30, "?" en /equipos tras una fusion).
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
            $kills = Kill::where('match_id', $match->id)->get(['attacker_player_id', 'attacker_guid', 'victim_player_id', 'victim_guid']);

            // Guid -> player_id valido para esta partida puntual (un guid
            // historico puede no tener fila en `players` si el jugador se
            // fusiono despues, pero la kill de esa partida todavia sabe a
            // que player_id apuntaba en su momento).
            $guidToPlayerId = [];
            foreach ($kills as $kill) {
                if ($kill->attacker_player_id) {
                    $guidToPlayerId[$kill->attacker_guid] = $kill->attacker_player_id;
                }
                if ($kill->victim_player_id) {
                    $guidToPlayerId[$kill->victim_guid] = $kill->victim_player_id;
                }
            }

            $participantPlayerIds = $kills->pluck('attacker_player_id')->merge($kills->pluck('victim_player_id'))
                ->filter()->unique();

            foreach ($participantPlayerIds as $playerId) {
                $played[$playerId] = ($played[$playerId] ?? 0) + 1;
            }

            $winningGuids = TeamSideAnalyzer::winningRosterGuids($match->rounds);
            if ($winningGuids) {
                foreach ($winningGuids as $guid) {
                    $playerId = $guidToPlayerId[$guid] ?? null;
                    if ($playerId) {
                        $won[$playerId] = ($won[$playerId] ?? 0) + 1;
                    }
                }
            }
        }

        $impactPoints = ImpactScoreCalculator::calculate($server->id, $matchIds);

        $qualified = collect();
        foreach ($stats as $guid => $stat) {
            $playerId = $stat->player->id;
            $playedCount = $played[$playerId] ?? 0;
            if ($playedCount < self::MIN_MATCHES) {
                continue;
            }

            $qualified->push((object) [
                'guid' => $guid,
                'player' => $stat->player,
                'kd' => $stat->deaths > 0 ? round($stat->kills / $stat->deaths, 2) : $stat->kills,
                'hsPct' => $stat->kills > 0 ? round($stat->headshots / $stat->kills * 100, 1) : 0,
                'nadePct' => $stat->kills > 0 ? round($stat->grenade_kills / $stat->kills * 100, 1) : 0,
                'winPct' => round(min($won[$playerId] ?? 0, $playedCount) / $playedCount * 100, 1),
                'impact' => round($impactPoints[$playerId] ?? 0.0, 2),
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
        $impactPct = $percentiles('impact');

        $qualified = $qualified->values()->map(function ($row, $i) use ($kdPct, $winPctPct, $impactPct) {
            // kdPct se guarda aparte del score final -- es el mismo "100%
            // percentil KD" que usa la semilla de temporada nueva
            // (seasonSeedScore()), sin mezclar win rate/impacto.
            $row->kdPct = $kdPct[$i];
            $row->score = round($winPctPct[$i] * 0.5 + $kdPct[$i] * 0.3 + $impactPct[$i] * 0.2, 1);

            return $row;
        })->sortByDesc('score')->values();

        $qualified = $qualified->map(function ($row, $i) use ($n) {
            // Posicion percentil DENTRO de la tabla ordenada (no el valor del
            // score) -- 100 = el mejor puesto, 0 = el ultimo. Ver el
            // comentario de clase para los cortes S/A/B/C/D.
            $percentTabla = $n > 1 ? round((1 - $i / ($n - 1)) * 100, 4) : 100.0;
            $row->rango = self::tierForPercent($percentTabla);

            return $row;
        });

        return $qualified->keyBy('guid');
    }

    private static function tierForPercent(float $percentTabla): string
    {
        foreach (self::TIER_CUTOFFS as $tier => $cutoff) {
            if ($percentTabla >= $cutoff) {
                return $tier;
            }
        }

        return 'D';
    }

    /**
     * Semilla de MMR oculto para el arranque de una temporada nueva
     * (2026-09-02, a pedido del dueño) -- SOLO para uso interno de
     * TeamBalancer mientras un jugador todavia no llega a MIN_MATCHES en la
     * temporada actual. Nunca se muestra en /rango (esa pagina sigue
     * excluyendo a cualquiera bajo MIN_MATCHES, sin cambios) -- es
     * exclusivamente para que Equipos pueda armar un balance razonable desde
     * la primera partida de la temporada nueva, en vez de tratar a todo el
     * mundo como "sin rango" (score neutro) hasta que cada quien acumule
     * partidas de cero otra vez.
     *
     * "La metrica con el menor sesgo posible": 100% el percentil de K/D que
     * el jugador tenia en la temporada INMEDIATAMENTE ANTERIOR (la mas
     * reciente ya cerrada, la que sea) -- nunca el score combinado (que ya
     * incluye impacto/win rate, mas sesgado por el roster de esa temporada
     * vieja). Devuelve null si no hay temporada anterior cerrada, o si el
     * jugador no calificaba (MIN_MATCHES) en esa temporada -- en ambos casos
     * TeamBalancer cae al score neutro de siempre.
     */
    public static function seasonSeedScore(Server $server, int $guid): ?float
    {
        $previousSeason = Season::where('id', '!=', Season::current()->id)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at')
            ->first();

        if (! $previousSeason) {
            return null;
        }

        return self::calculateForServer($server, $previousSeason->id)->get($guid)?->kdPct;
    }
}
