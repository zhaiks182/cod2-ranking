<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostedServer extends Model
{
    // Todo servidor temporal se identifica como parte de la comunidad, a pedido del
    // dueño (2026-08-22) -- el nombre que el usuario tipea es solo la parte editable,
    // este sufijo se pega siempre al final antes de guardar (ver
    // HostedServerController::store()), nunca es editable ni opcional.
    public const NAME_SUFFIX = ' @ Pug Latam';

    // Deja lugar para el sufijo de arriba dentro del tope real de 32 caracteres que
    // ya usa HostedServerSanitizer::cfgValue() al escribir sv_hostname en el cfg.
    public const NAME_MAX_LENGTH = 32 - 12; // strlen(NAME_SUFFIX) = 12

    protected $fillable = [
        'hostname', 'slots', 'map', 'join_password', 'rcon_password', 'cracked',
        'port', 'management_token', 'status', 'player_count', 'last_seen_players_at',
        'expires_at', 'stopped_at', 'creator_ip',
    ];

    protected $casts = [
        'rcon_password' => 'encrypted',
        'cracked' => 'boolean',
        'last_seen_players_at' => 'datetime',
        'expires_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    // Nunca se listan/serializan -- rcon_password es la contraseña real del server
    // (nadie la necesita salvo el backend), management_token es la unica credencial
    // del creador y solo se compara server-side contra la ruta, jamas se muestra
    // de vuelta en una vista.
    protected $hidden = ['rcon_password', 'management_token'];

    public function isActive(): bool
    {
        return in_array($this->status, ['starting', 'running'], true);
    }

    public function connectString(): string
    {
        $ip = config('cod2.connect_ip');
        $connect = "/connect {$ip}:{$this->port}";

        return $this->join_password ? "{$connect}; password {$this->join_password}" : $connect;
    }
}
