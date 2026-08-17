<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerMapStat extends Model
{
    protected $fillable = ['player_id', 'server_id', 'map', 'kills', 'deaths', 'headshots', 'grenade_kills', 'teamkills'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
