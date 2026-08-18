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
