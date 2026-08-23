<?php

return [
    // 2, a pedido del dueño (2026-08-22), sabiendo que es ajustado: una prueba real en
    // vivo mostro que UNA instancia sola (150M de tope, 119M de uso real) dejo el VPS
    // con solo 176MB de RAM "disponible" (de 286MB que habia libres antes de esa
    // prueba). Con 2 concurrentes el margen libre puede bajar a ~60MB en el peor caso
    // -- ajustado mas no imposible, y OOMScoreAdjust=500 en cod2-temp@.service hace
    // que una instancia temporal sea la PRIMERA candidata del OOM killer si el sistema
    // se queda sin memoria, protegiendo al server real y al panel. Si en la practica
    // esto genera problemas, bajar de nuevo a 1. Ver CLAUDE.md, seccion "Servidores
    // temporales self-service".
    'max_concurrent' => env('HOSTED_SERVERS_MAX_CONCURRENT', 2),

    'expiry_hours' => env('HOSTED_SERVERS_EXPIRY_HOURS', 3),

    // 5, a pedido del dueño (2026-08-22). Ver PollHostedServers/ExpireHostedServers
    // para el mecanismo real de deteccion (ninguno de los dos re-lee este valor con
    // frecuencia distinta, ambos corren cada minuto vía el cron ya existente).
    'idle_minutes' => env('HOSTED_SERVERS_IDLE_MINUTES', 5),

    // Debe tener exactamente max_concurrent puertos (o mas) -- ver CLAUDE.md, no
    // vale la pena tener dos numeros por sincronizar a mano cuando se sube el tope.
    'port_range_start' => env('HOSTED_SERVERS_PORT_START', 28970),
    'port_range_end' => env('HOSTED_SERVERS_PORT_END', 28971),

    'slots_min' => env('HOSTED_SERVERS_SLOTS_MIN', 2),
    // 20, a pedido del dueño (2026-08-22, subido de 12).
    'slots_max' => env('HOSTED_SERVERS_SLOTS_MAX', 20),

    // Directorio POR INSTANCIA (fs_homepath) -- la base de juego real (fs_basepath,
    // mapas/mod/binario) es la que ya usa produccion, /home/gameserver/1.3/puG, y
    // nunca se duplica.
    'base_dir' => env('HOSTED_SERVERS_BASE_DIR', '/home/gameserver/1.3/temp'),
    'game_base_dir' => env('HOSTED_SERVERS_GAME_BASE_DIR', '/home/gameserver/1.3/puG'),

    // Unit systemd template (%i = hosted_servers.id) -- instalado a mano en el VPS,
    // no versionado en este repo (ver CLAUDE.md, mismo precedente que cod2server.service).
    'systemd_template' => env('HOSTED_SERVERS_SYSTEMD_TEMPLATE', 'cod2-temp@'),
];
