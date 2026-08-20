<?php

namespace App\Support;

class HwidHasher
{
    /**
     * Reproduce el hash FNV-1a de 32 bits que CoD2x aplica al HWID2 (el hex de 32
     * caracteres que devuelve self getHWID() en GSC) para calcular el GUID que
     * despues escribe en cada linea del log -- ver server.cpp del repo de CoD2x,
     * SV_DirectConnect(). Confirmado en vivo contra un jugador real (2026-08-19):
     * mismo hwid hex -> mismo players.guid.
     */
    public static function hwidToGuid(string $hwid): int
    {
        $hash = 2166136261;

        for ($i = 0; $i < strlen($hwid); $i++) {
            $hash ^= ord($hwid[$i]);
            $hash = ($hash * 16777619) & 0xFFFFFFFF;
        }

        if ($hash === 0) {
            $hash = 1;
        }

        // El motor guarda el GUID como int32 con signo (columna players.guid).
        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }
}
