<?php

return [
    // Arranca conservador (3) hasta ver uso real de CPU/RAM en el VPS de produccion
    // con instancias temporales corriendo en paralelo al server real de Pug Latam --
    // subir con confianza despues de confirmarlo en la practica, no antes.
    'max_concurrent' => env('HOSTED_SERVERS_MAX_CONCURRENT', 3),

    'expiry_hours' => env('HOSTED_SERVERS_EXPIRY_HOURS', 3),

    'idle_minutes' => env('HOSTED_SERVERS_IDLE_MINUTES', 15),

    // Debe tener exactamente max_concurrent puertos (o mas) -- ver CLAUDE.md, no
    // vale la pena tener dos numeros por sincronizar a mano cuando se sube el tope.
    'port_range_start' => env('HOSTED_SERVERS_PORT_START', 28970),
    'port_range_end' => env('HOSTED_SERVERS_PORT_END', 28972),

    'slots_min' => env('HOSTED_SERVERS_SLOTS_MIN', 2),
    'slots_max' => env('HOSTED_SERVERS_SLOTS_MAX', 12),

    // Directorio POR INSTANCIA (fs_homepath) -- la base de juego real (fs_basepath,
    // mapas/mod/binario) es la que ya usa produccion, /home/gameserver/1.3/puG, y
    // nunca se duplica.
    'base_dir' => env('HOSTED_SERVERS_BASE_DIR', '/home/gameserver/1.3/temp'),
    'game_base_dir' => env('HOSTED_SERVERS_GAME_BASE_DIR', '/home/gameserver/1.3/puG'),

    // Unit systemd template (%i = hosted_servers.id) -- instalado a mano en el VPS,
    // no versionado en este repo (ver CLAUDE.md, mismo precedente que cod2server.service).
    'systemd_template' => env('HOSTED_SERVERS_SYSTEMD_TEMPLATE', 'cod2-temp@'),
];
