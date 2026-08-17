<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    protected $fillable = [
        'server_id', 'match_id', 'map', 'gametype', 'round_label', 'started_at', 'ended_at', 'winner_guids',
        'round_number', 'score_after_allies', 'score_after_axis',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'winner_guids' => 'array',
    ];

    public function kills(): HasMany
    {
        return $this->hasMany(Kill::class);
    }

    public function server(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function match(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
