<?php

namespace App\Support;

/**
 * Categorias de contenido de la galeria (2026-09-02, a pedido del dueño) --
 * el usuario elige una al subir (opcional), para poder filtrar el listado
 * por tipo de clip. Codigo fijo en este archivo (no editable desde admin,
 * a diferencia de la cuota/tope de video) -- si hace falta agregar/sacar
 * una categoria, se edita esta lista.
 */
class GalleryCategory
{
    public const OPTIONS = [
        'buenos_tiros' => 'Buenos tiros',
        'troll' => 'Troll',
        'noobeadas' => 'Noobeadas',
        'fail' => 'Fail',
        'graciosos' => 'Graciosos',
        'rage' => 'Rage',
        'otro' => 'Otro',
    ];

    public static function label(?string $code): ?string
    {
        return $code ? (self::OPTIONS[$code] ?? null) : null;
    }
}
