<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerMatchExtra extends Model
{
    protected $fillable = [
        'player_id', 'match_id', 'bomb_plants', 'bomb_defuses',
        'damage_dealt', 'damage_taken', 'mid_round_disconnects',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
