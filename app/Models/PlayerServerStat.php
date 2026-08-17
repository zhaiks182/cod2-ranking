<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerServerStat extends Model
{
    protected $fillable = [
        'player_id', 'server_id', 'kills', 'deaths', 'headshots', 'grenade_kills', 'suicides', 'teamkills',
        'bomb_plants', 'bomb_defuses', 'damage_dealt', 'damage_taken', 'mid_round_disconnects',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function getKdRatioAttribute(): float
    {
        return $this->deaths > 0 ? round($this->kills / $this->deaths, 2) : (float) $this->kills;
    }
}
