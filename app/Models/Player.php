<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'guid', 'last_name', 'last_name_plain', 'ip',
        'kills_total', 'deaths_total', 'headshots_total', 'grenade_kills_total', 'suicides_total',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(PlayerAlias::class);
    }

    public function kills(): HasMany
    {
        return $this->hasMany(Kill::class, 'attacker_player_id');
    }

    public function deaths(): HasMany
    {
        return $this->hasMany(Kill::class, 'victim_player_id');
    }

    public function mapStats(): HasMany
    {
        return $this->hasMany(PlayerMapStat::class);
    }

    public function getKdRatioAttribute(): float
    {
        return $this->deaths_total > 0
            ? round($this->kills_total / $this->deaths_total, 2)
            : (float) $this->kills_total;
    }

    public function getHeadshotRateAttribute(): float
    {
        return $this->kills_total > 0
            ? round(($this->headshots_total / $this->kills_total) * 100, 1)
            : 0.0;
    }
}
