<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerAlias extends Model
{
    protected $fillable = ['player_id', 'name', 'name_plain', 'last_seen_at'];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
