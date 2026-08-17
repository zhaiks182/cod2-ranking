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
];
