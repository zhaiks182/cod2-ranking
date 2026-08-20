<?php

namespace App\Support;

use App\Models\GameMatch;
use Carbon\Carbon;

class DemoMatchResolver
{
    /**
     * La URL de subida del demo no lleva ningun id de partida (ver _record.gsc), asi
     * que se infiere por tiempo: la partida no-importada mas reciente que empezo
     * hasta 90s DESPUES de $at. Ese margen existe porque el cliente sube el demo
     * (casi instantaneo) antes de que 'cod2:parse-log' (corre cada minuto via cron)
     * alcance a crear la fila de la partida nueva -- confirmado en vivo: un demo con
     * created_at 12:19:49 debia ir a la partida que recien se creo a las 12:20:02.
     * Por eso esto se llama tanto al subir el demo (mejor intento inmediato) como
     * desde 'demos:reconcile-matches' (que corrige una vez el parser se pone al dia).
     */
    public static function resolve(Carbon $at): ?GameMatch
    {
        $match = GameMatch::where('is_backfilled', false)
            ->where('started_at', '<=', $at->clone()->addSeconds(90))
            ->orderByDesc('started_at')
            ->first();

        if (! $match || $match->started_at->lt($at->clone()->subHours(3))) {
            return null;
        }

        return $match;
    }
}
