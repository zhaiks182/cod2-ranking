<?php

namespace App\Models;

use App\Support\TeamSideAnalyzer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameMatch extends Model
{
    protected $table = 'matches';

    protected $fillable = ['server_id', 'map', 'gametype', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_backfilled' => 'boolean',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class, 'match_id');
    }

    public function kills(): HasMany
    {
        return $this->hasMany(Kill::class, 'match_id');
    }

    /**
     * Human-readable elapsed time — counts up to now() while the match has no
     * ended_at yet, so an in-progress match shows a live-growing duration instead of
     * nothing.
     */
    public function getDurationLabelAttribute(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        $minutes = (int) floor($this->started_at->diffInMinutes($this->ended_at ?? now()));

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return "{$hours}h {$remaining}min";
    }

    /**
     * Tallies rounds by which roster won them (not which side/axis-allies they
     * played, since that swaps at halftime) — see TeamSideAnalyzer::clusterRoundWinners()
     * for how rounds get classified into the two rosters.
     */
    public function getFinalScoreAttribute(): ?string
    {
        $clusters = TeamSideAnalyzer::clusterRoundWinners($this->rounds);

        if (! $clusters) {
            return null;
        }

        return max($clusters['A']['score'], $clusters['B']['score']).'-'.min($clusters['A']['score'], $clusters['B']['score']);
    }
}
