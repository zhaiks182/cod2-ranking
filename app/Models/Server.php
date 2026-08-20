<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $fillable = [
        'name', 'slug', 'log_path', 'systemd_service',
        'rcon_host', 'rcon_port', 'rcon_password',
        'connect_ip', 'connect_port', 'join_password', 'max_clients', 'is_active',
    ];

    protected $casts = [
        'rcon_password' => 'encrypted',
        'is_active' => 'boolean',
    ];

    protected $hidden = ['rcon_password'];

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerServerStat::class);
    }
}
