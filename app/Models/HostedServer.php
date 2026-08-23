<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostedServer extends Model
{
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
