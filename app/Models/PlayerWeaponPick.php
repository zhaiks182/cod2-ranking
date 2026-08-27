<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerWeaponPick extends Model
{
    protected $fillable = ['player_id', 'season_id', 'weapon', 'picks'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
