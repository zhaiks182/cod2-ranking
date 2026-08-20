<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ban extends Model
{
    protected $fillable = [
        'player_id', 'guid', 'player_name', 'reason', 'banned_by', 'unbanned_at', 'unbanned_by',
    ];

    protected $casts = [
        'unbanned_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    public function unbannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unbanned_by');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->unbanned_at === null;
    }
}
