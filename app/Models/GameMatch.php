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

    public function demos(): HasMany
    {
        return $this->hasMany(Demo::class, 'match_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id');
    }

    /**
     * A match only reaches a real result once it hits 13 rounds or the mod's own
     * MatchEnd; log line fires (a 12-12 tie goes to overtime and keeps playing
     * past round 13 without a satisfied win condition until MatchEnd; decides
     * it — see TeamSideAnalyzer::clusterRoundWinners()). Matches abandoned
     * before either point are real gameplay (unlike the Round 0 ready-up noise
     * ParseCod2Log already filters out), just never concluded — confirmed with
     * the owner: these shouldn't appear in listings as if they were finished.
     */
    public function scopeReachedConclusion($query)
    {
        return $query->where(function ($q) {
            $q->has('rounds', '>=', 13)
                ->orWhereHas('events', fn ($eq) => $eq->where('event_type', 'match_end'));
        });
    }

    /**
     * What listings should show: matches still being played right now (no
     * ended_at yet — could still go on to reach a real result) plus matches
     * that already reached one. Excludes only matches that are over (ended_at
     * set, e.g. via the 30-minute gap timeout in ParseCod2Log) without ever
     * reaching 13 rounds or MatchEnd; — abandoned/aborted starts, not a live
     * match that simply hasn't finished yet.
     */
    public function scopeVisibleInListing($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('ended_at')->orWhere(fn ($q2) => $q2->reachedConclusion());
        });
    }

    /**
     * The inverse of scopeVisibleInListing() — matches whose kills/rounds
     * should NOT count toward player_server_stats/player_map_stats/kills_total
     * either (confirmed with the owner: an abandoned match's kills shouldn't
     * feed the ranking, only real gameplay that actually concluded). Kept as
     * its own scope, rather than inverting visibleInListing() at each call
     * site, so StatsRecalculator gets a plain list of match ids to exclude.
     */
    public function scopeAbandonedWithoutConclusion($query)
    {
        return $query->whereNotNull('ended_at')->where(function ($q) {
            $q->has('rounds', '<', 13)
                ->whereDoesntHave('events', fn ($eq) => $eq->where('event_type', 'match_end'));
        });
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
