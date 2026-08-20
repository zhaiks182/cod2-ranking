<?php

namespace App\Support;

use App\Models\GameMatch;
use Carbon\Carbon;

class DemoMatchResolver
{
    /**
     * Abreviatura de mapa que _record.gsc::generateDemoName() mete en el nombre del
     * demo, mapeada de vuelta a codigo(s) de mapa real. Los primeros 8 casos son
     * literales (if/elseif exacto en el mod); todo lo demas cae al fallback dinamico
     * del propio GSC: sacar el prefijo "mp_" y truncar a 3 letras -- reproducido acá
     * para el resto de los mapas del catalogo. "bre" es ambiguo a proposito (mismo
     * problema en el mod original): mp_breakout_tls (caso literal) Y mp_brecourt
     * (fallback dinamico) generan la misma abreviatura -- se listan ambos como
     * candidatos, total el filtro por tiempo abajo desempata en la practica.
     */
    private const MAP_ABBREVIATIONS = [
        'tj' => ['mp_toujane'],
        'bg' => ['mp_burgundy'],
        'dw' => ['mp_dawnville'],
        'mat' => ['mp_matmata'],
        'car' => ['mp_carentan'],
        'bre' => ['mp_breakout', 'mp_brecourt'],
        'che' => ['mp_chelm'],
        'crs' => ['mp_crossroads'],
        'far' => ['mp_farmhouse'],
        'tra' => ['mp_trainstation'],
        'dec' => ['mp_decoy'],
        'len' => ['mp_leningrad'],
        'dow' => ['mp_downtown'],
        'har' => ['mp_harbor'],
        'rai' => ['mp_railyard'],
        'rhi' => ['mp_rhine'],
    ];

    /**
     * La URL de subida del demo no lleva ningun id de partida (ver _record.gsc), asi
     * que se infiere por tiempo: la partida no-importada mas reciente que empezo
     * hasta 90s DESPUES de $at. Ese margen existe porque el cliente sube el demo
     * (casi instantaneo) antes de que 'cod2:parse-log' (corre cada minuto via cron)
     * alcance a crear la fila de la partida nueva -- confirmado en vivo: un demo con
     * created_at 12:19:49 debia ir a la partida que recien se creo a las 12:20:02.
     * Por eso esto se llama tanto al subir el demo (mejor intento inmediato) como
     * desde 'demos:reconcile-matches' (que corrige una vez el parser se pone al dia).
     *
     * $demoName es opcional (y solo aporta si trae una abreviatura de mapa reconocible)
     * porque la grabacion puede seguir activa mucho mas alla de esos 90s -- confirmado
     * 2026-08-19: si el roster de jugadores se mantiene estable entre partidas (nadie
     * se desconecta), _matchinfo.gsc nunca dispara stopRecordingForAll() al cambiar de
     * mapa, asi que el demo sigue grabando (con el nombre del mapa donde arranco) hasta
     * que el jugador se desconecta de verdad, mucho despues de que esa partida haya
     * terminado. Ahi la heuristica de "la partida mas reciente" pega mal -- devuelve la
     * ULTIMA partida que estaban jugando al momento de subir, no la partida real donde
     * arranco la grabacion. Con la pista del mapa se busca directo en ese mapa en vez
     * de confiar en la proximidad de tiempo.
     */
    public static function resolve(Carbon $at, ?string $demoName = null): ?GameMatch
    {
        if ($demoName && ($mapCodes = self::mapCodesFromDemoName($demoName))) {
            // "mp_toujane%" matea el codigo base y cualquier variante de comunidad
            // (_fix, _v2, ...) sin tener que normalizar cada fila -- ver
            // MapCatalog::normalize() para el mismo criterio de sufijo usado en el
            // resto del sitio.
            $match = GameMatch::where('is_backfilled', false)
                ->where(function ($q) use ($mapCodes) {
                    foreach ($mapCodes as $code) {
                        $q->orWhere('map', 'like', $code.'%');
                    }
                })
                ->where('started_at', '<=', $at)
                ->orderByDesc('started_at')
                ->first();

            if ($match) {
                return $match;
            }
        }

        $match = GameMatch::where('is_backfilled', false)
            ->where('started_at', '<=', $at->clone()->addSeconds(90))
            ->orderByDesc('started_at')
            ->first();

        if (! $match || $match->started_at->lt($at->clone()->subHours(3))) {
            return null;
        }

        return $match;
    }

    /**
     * @return array<int, string>|null codigos base de mapa (normalizados), o null si
     *                                  el nombre no trae una abreviatura reconocible
     */
    private static function mapCodesFromDemoName(string $demoName): ?array
    {
        // La abreviatura es el segmento antes de un posible "_ot"/"_r<N>" final (sufijos
        // de overtime/ronda que generateDemoName() agrega solo para sd/re) o antes de un
        // "#gametype" (para el resto de los gametypes) -- ver _record.gsc.
        if (! preg_match('/_([a-z]{2,3})(?:_ot)?(?:_r\d+)?$/', $demoName, $m)
            && ! preg_match('/_([a-z]{2,3})#/', $demoName, $m)) {
            return null;
        }

        return self::MAP_ABBREVIATIONS[$m[1]] ?? null;
    }
}
