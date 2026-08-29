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
];
