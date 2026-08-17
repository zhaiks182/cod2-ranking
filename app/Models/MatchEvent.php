<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['server_id', 'match_id', 'round_id', 'event_type', 'side', 'name', 'occurred_at'];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }
}
