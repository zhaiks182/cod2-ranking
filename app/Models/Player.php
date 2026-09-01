<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'guid', 'last_name', 'last_name_plain', 'ip', 'icon_path',
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

    public function bans(): HasMany
    {
        return $this->hasMany(Ban::class);
    }

    public function siteUser(): HasOne
    {
        return $this->hasOne(SiteUser::class);
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

    /**
     * Icono personalizado subido desde /adm_cod2/jugadores/iconos (2026-08-28),
     * ya normalizado a un cuadrado chico por PlayerIcon::store() -- null si el
     * jugador nunca tuvo uno.
     */
    public function getIconUrlAttribute(): ?string
    {
        return $this->icon_path ? Storage::disk('public')->url($this->icon_path) : null;
    }
}
