<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kill extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'round_id', 'match_id',
        'attacker_player_id', 'attacker_guid', 'attacker_name', 'attacker_team',
        'victim_player_id', 'victim_guid', 'victim_name', 'victim_team',
        'weapon', 'damage', 'mod', 'hitloc',
        'is_headshot', 'is_grenade', 'is_suicide', 'is_teamkill',
        'occurred_at',
    ];

    protected $casts = [
        'is_headshot' => 'boolean',
        'is_grenade' => 'boolean',
        'is_suicide' => 'boolean',
        'is_teamkill' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'attacker_player_id');
    }

    public function victim(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'victim_player_id');
    }
}
