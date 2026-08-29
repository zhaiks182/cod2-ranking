<?php

return [
    'log_path' => env('COD2_LOG_PATH', '/home/gameserver/1.3/puG/main/games_mp.log'),

    'connect_ip' => env('COD2_CONNECT_IP', '167.148.33.82'),
    'connect_port' => env('COD2_CONNECT_PORT', 28960),
    'hostname' => env('COD2_HOSTNAME', "Pug Latam ^6~ ^7private server^6'"),
    'max_clients' => env('COD2_MAX_CLIENTS', 30),

    'rcon' => [
        'host' => env('COD2_RCON_HOST', '127.0.0.1'),
        'port' => env('COD2_RCON_PORT', 28960),
        'password' => env('COD2_RCON_PASSWORD'),
    ],

    // Carpeta real de fast-download del gameserver (la misma que sirve el propio
    // juego via sv_wwwBaseURL en server.cfg, ver "Descargas" en /adm_cod2 -- HelpController
    // la lista para mostrarla en el sitio, no la duplica). Simlinkeada en
    // public/fastdl (mismo patron que storage:link) para que Apache la sirva
    // directo, sin pasar por PHP-FPM -- el paquete de mapas pesa >150MB.
    'fast_download_path' => env('COD2_FAST_DOWNLOAD_PATH', '/var/www/html/cod2/main'),

    // Raiz publica real del fast-download, un nivel arriba de fast_download_path --
    // lo que Apache sirve directo por el vhost por defecto en http://<ip>/cod2/ (ese
    // directorio no cuelga del dominio cod2.4livepro.com, que tiene su propio
    // DocumentRoot en public/). HelpController::browseFiles() navega esta carpeta
    // completa (con subdirectorios), no solo el nivel plano de fast_download_path.
    'fast_download_root' => env('COD2_FAST_DOWNLOAD_ROOT', '/var/www/html/cod2'),

    // URL publica real de arriba -- los links de descarga de /descargas/archivos
    // apuntan aca (no a esta app), asi el archivo se sirve directo por Apache sin
    // pasar por PHP-FPM (el paquete de mapas pesa >150MB). Via el dominio HTTPS real
    // (Alias /cod2 -> fast_download_root agregado al vhost de cod2.4livepro.com, con
    // el mismo cert Let's Encrypt del sitio), no la IP cruda por HTTP -- la version
    // anterior (http://151.245.32.43/cod2) generaba "mixed content": Chrome/Edge
    // bloquean en silencio la descarga de un link http:// clickeado desde una pagina
    // https:// (2026-08-29, reportado por el dueño: el click no descargaba nada).
    'fast_download_public_url' => env('COD2_FAST_DOWNLOAD_PUBLIC_URL', 'https://cod2.4livepro.com/cod2'),
];
