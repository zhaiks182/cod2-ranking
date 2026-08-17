<?php

namespace App\Support;

class Cod2Colors
{
    private const MAP = [
        '0' => '#000000', '1' => '#ff3b3b', '2' => '#3bff3b', '3' => '#ffff3b',
        '4' => '#3b3bff', '5' => '#3bffff', '6' => '#ff3bff', '7' => '#ffffff',
        '8' => '#ffffff', '9' => '#808080',
    ];

    public static function stripColors(string $name): string
    {
        return trim(preg_replace('/\^[0-9]/', '', $name));
    }

    public static function toHtml(string $name): string
    {
        $escaped = e($name);
        $html = '<span style="color:#ffffff">';
        $html .= preg_replace_callback('/\^([0-9])/', function ($m) {
            return '</span><span style="color:'.(self::MAP[$m[1]] ?? '#ffffff').'">';
        }, $escaped);

        return $html.'</span>';
    }
}
