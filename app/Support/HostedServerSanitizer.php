<?php

namespace App\Support;

/**
 * hostname/join_password terminan escritos crudos dentro de un server.cfg que el
 * motor `+exec`uta linea por linea -- sin esto, un valor como `foo"; set rcon_password
 * "hijacked` cierra la comilla de su propio `set` y le agrega comandos arbitrarios al
 * cfg de esa instancia (inyeccion de config, mismo tipo de problema que una inyeccion
 * SQL pero contra el parser de cvars de CoD2). Allowlist en vez de blocklist a
 * proposito: mas facil de auditar que "que caracteres bloqueo" cuando la lista es
 * "que caracteres permito".
 */
class HostedServerSanitizer
{
    public static function cfgValue(string $value, int $maxLength): string
    {
        // Letras/numeros/espacios, puntuacion basica, codigos de color de CoD2 (^0-^9),
        // y "@" (necesario para el sufijo fijo " @ Pug Latam" que HostedServerController
        // le pega a todo hostname -- confirmado en vivo 2026-08-22 que sin "@" en esta
        // lista el sufijo llegaba al juego como "Nombre  Pug Latam", sin el arroba,
        // porque este mismo sanitizer se lo comia). Cualquier otra cosa (comillas,
        // backticks, ;, $, saltos de linea) se descarta en vez de intentar escaparla.
        $clean = preg_replace('/[^A-Za-z0-9 .,!?\'\-\^@]/', '', $value) ?? '';

        // Un "^" suelto (no seguido de digito) no es un codigo de color valido -- se
        // saca para no dejar basura visual ni casos raros en el parser de colores.
        $clean = preg_replace('/\^(?!\d)/', '', $clean) ?? '';

        return mb_substr(trim($clean), 0, $maxLength);
    }
}
