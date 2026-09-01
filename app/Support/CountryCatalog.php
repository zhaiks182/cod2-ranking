<?php

namespace App\Support;

/**
 * Lista de paises para el selector de "Pais" en /mi-cuenta (2026-09-01) --
 * antes era un input de texto libre (sin bandera posible, cualquier cosa
 * podia terminar ahi). Enfocada en Latinoamerica + Espana + EEUU, el area
 * real de esta comunidad ("Pug Latam"). El codigo es el mismo que usa
 * GeoIp::flagIconHtml() (ISO 3166-1 alpha-2, minuscula) para la bandera.
 */
class CountryCatalog
{
    public const OPTIONS = [
        'ar' => 'Argentina',
        'bo' => 'Bolivia',
        'cl' => 'Chile',
        'co' => 'Colombia',
        'cr' => 'Costa Rica',
        'cu' => 'Cuba',
        'ec' => 'Ecuador',
        'sv' => 'El Salvador',
        'es' => 'España',
        'us' => 'Estados Unidos',
        'gt' => 'Guatemala',
        'hn' => 'Honduras',
        'mx' => 'México',
        'ni' => 'Nicaragua',
        'pa' => 'Panamá',
        'py' => 'Paraguay',
        'pe' => 'Perú',
        'pr' => 'Puerto Rico',
        'do' => 'República Dominicana',
        'uy' => 'Uruguay',
        've' => 'Venezuela',
    ];
}
