<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['server_id', 'match_id', 'player_id', 'guid', 'name', 'message', 'channel', 'occurred_at'];

    protected $casts = [
        'occurred_at' => 'datetime',
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
