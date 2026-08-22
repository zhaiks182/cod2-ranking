<?php

namespace App\Support;

class MapCatalog
{
    private const MAPS = [
        'mp_farmhouse' => 'Beltot, France',
        'mp_brecourt' => 'Brecourt, France',
        'mp_burgundy' => 'Burgundy, France',
        'mp_trainstation' => 'Caen, France',
        'mp_carentan' => 'Carentan, France',
        'mp_decoy' => 'El Alamein, Egypt',
        'mp_leningrad' => 'Leningrad, Russia',
        'mp_matmata' => 'Matmata, Tunisia',
        'mp_downtown' => 'Moscow, Russia',
        'mp_harbor' => 'Rostov, Russia',
        'mp_dawnville' => 'St. Mere Eglise, France',
        'mp_railyard' => 'Stalingrad, Russia',
        'mp_toujane' => 'Toujane, Tunisia',
        'mp_breakout' => 'Villers-Bocage, France',
        'mp_rhine' => 'Wallendar, Germany',
    ];

    /** @return array<string,string> map code => pretty label, for building <select> options */
    public static function all(): array
    {
        return self::MAPS;
    }

    /**
     * Community reuploads/variants of a stock map keep the original layout but ship
     * under a suffixed code — mp_xxx_fix, _fix2, _v2, and (confirmed live,
     * 2026-08-15) _tls, _sun, one new suffix per variant as the community produces
     * them. Rather than growing an exact-suffix whitelist forever, strip whatever the
     * last underscore segment is and check if the bare base is a known map — same
     * map, different code, so the label and any uploaded image should apply to every
     * variant, not just the exact code.
     */
    public static function normalize(string $code): string
    {
        if (array_key_exists($code, self::MAPS)) {
            return $code;
        }

        $base = preg_replace('/_[A-Za-z0-9]+$/', '', $code);

        return array_key_exists($base, self::MAPS) ? $base : $code;
    }

    public static function mapLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $code = self::normalize($code);

        return self::MAPS[$code] ?? ucwords(str_replace(['mp_', '_'], ['', ' '], $code));
    }

    /**
     * Listado completo de mapas custom instalados en el server (confirmado 2026-08-18
     * contra el directorio real de mapas del dueño, los .d3dbsp/.gsc de cada variante)
     * — mp_chelm_fix, mp_crossroads y mp_vallente_fix no tienen entrada en MAPS (no
     * son mapas stock de CoD2, mapLabel() cae al fallback generico basado en el
     * codigo) y wawa_3daim no lleva el prefijo "mp_" (mapa de aim-trainer para
     * Deathmatch, ver "Cuando se crea una partida" en CLAUDE.md). Vivia duplicada
     * como un @php local en admin/console.blade.php; centralizada aca para no tener
     * que acordarse de actualizar dos listas cuando aparezca una variante nueva.
     */
    private const VARIANT_SUFFIXES = [
        'mp_breakout_tls' => 'TLS',
        'mp_burgundy_fix' => 'FIX',
        'mp_carentan_bal' => 'BAL', 'mp_carentan_fix' => 'FIX',
        'mp_chelm_fix' => 'FIX',
        'mp_crossroads' => null,
        'mp_dawnville_fix' => 'FIX', 'mp_dawnville_sun' => 'SUN',
        'mp_leningrad_mjr' => 'MJR', 'mp_leningrad_tls' => 'TLS',
        'mp_matmata_fix' => 'FIX',
        'mp_railyard_mjr' => 'MJR',
        'mp_toujane_fix' => 'FIX',
        'mp_trainstation_bhg' => 'BHG', 'mp_trainstation_fix' => 'FIX',
        'mp_vallente_fix' => 'FIX',
        'wawa_3daim' => null,
    ];

    /** Etiqueta corta de variante (FIX, SUN, MJR, ...) para distinguir codigos crudos que comparten mapLabel(), o null si no es una variante conocida. */
    public static function variantSuffix(string $code): ?string
    {
        return self::VARIANT_SUFFIXES[$code] ?? null;
    }

    /** @return array<int,string> todos los codigos de variante conocidos (con o sin sufijo visible) */
    public static function variantCodes(): array
    {
        return array_keys(self::VARIANT_SUFFIXES);
    }

    /**
     * Suma stats de variantes del mismo mapa real (mp_dawnville_fix + mp_dawnville_sun
     * -> "St. Mere Eglise, France" una sola vez, en vez de dos filas identicas en
     * "Mejores mapas" con distinto codigo pero la misma etiqueta -- confirmado que
     * confundia, 2026-08-19, tambien reportado para Carentan (_fix vs _bal). Cada
     * item de $stats necesita ->map, ->server, ->kills, ->deaths, ->teamkills.
     * Devuelve stdClass con ->map_codes (los codigos originales agrupados) para que
     * el filtro de detalle de kills/fuego amigo pueda pedir las dos variantes juntas
     * en vez de perder la mitad del detalle.
     */
    public static function mergeVariants(\Illuminate\Support\Collection $stats): \Illuminate\Support\Collection
    {
        return $stats
            ->groupBy(fn ($s) => self::normalize($s->map))
            ->map(function ($group, $normalizedCode) {
                $first = $group->first();

                return (object) [
                    'map' => $normalizedCode,
                    'map_codes' => $group->pluck('map')->unique()->values()->all(),
                    'server' => $first->server,
                    'kills' => $group->sum('kills'),
                    'deaths' => $group->sum('deaths'),
                    'teamkills' => $group->sum('teamkills'),
                ];
            })
            ->sortByDesc('kills')
            ->values();
    }

    // Los 8 gametypes que trae zPAM 4.08 (confirmado 2026-08-18 contra los .txt de
    // maps/mp/gametypes/ dentro de zpam408.iwd — uno por codigo, sin mas variantes).
    private const GAMETYPES = [
        'dm' => 'Deathmatch',
        'tdm' => 'Team Deathmatch',
        'ctf' => 'Capture the Flag',
        'hq' => 'Headquarters',
        'sd' => 'Search and Destroy',
        'htf' => 'Hold the Flag',
        're' => 'Retrieval',
        'strat' => 'Strategy Planning',
    ];

    public static function gametypeLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return self::GAMETYPES[$code] ?? strtoupper($code);
    }
}
