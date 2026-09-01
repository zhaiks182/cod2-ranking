<?php

namespace App\Models;

use App\Support\TeamSideAnalyzer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameMatch extends Model
{
    protected $table = 'matches';

    protected $fillable = ['server_id', 'season_id', 'map', 'gametype', 'started_at', 'ended_at', 'discord_notified_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_backfilled' => 'boolean',
        'discord_notified_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
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
     * ended_at is NOT a stable "this match is truly over" signal — openRound()
     * sets it after every single round (right when RoundEnd;/ShutdownGame:
     * fires) and then clears it back to null the moment the NEXT round of the
     * same match starts (see openRound()'s "continuing" branch). Confirmed
     * live (2026-08-24): with bots keeping rounds cycling every ~1 minute,
     * ended_at was caught set during that brief between-rounds gap by
     * cod2:recalculate-stats, which zeroed out a player's kills for a match
     * that was still actively being played. log_parser_state.current_match_id
     * is the parser's own live pointer to "the match I'm still tracking for
     * this server" — as long as a match is still current, it hasn't been
     * superseded by anything (a new match on a different map, or the 30-min
     * gap heuristic creating a fresh one), so it can't be judged abandoned yet
     * no matter what ended_at says at this exact instant.
     */
    public function scopeStillCurrent($query)
    {
        $currentMatchIds = LogParserState::whereNotNull('current_match_id')->pluck('current_match_id');

        return $query->whereIn('id', $currentMatchIds);
    }

    /**
     * What listings should show: matches still being played right now (no
     * ended_at yet, or still the parser's current match for its server —
     * could still go on to reach a real result) plus matches that already
     * reached one. Excludes only matches that are over (ended_at set AND no
     * longer current, e.g. via the 30-minute gap timeout in ParseCod2Log)
     * without ever reaching 13 rounds or MatchEnd; — abandoned/aborted
     * starts, not a live match that simply hasn't finished yet.
     */
    public function scopeVisibleInListing($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('ended_at')
                ->orWhere(fn ($q2) => $q2->stillCurrent())
                ->orWhere(fn ($q2) => $q2->reachedConclusion());
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
        $currentMatchIds = LogParserState::whereNotNull('current_match_id')->pluck('current_match_id');

        return $query->whereNotNull('ended_at')
            ->whereNotIn('id', $currentMatchIds)
            ->where(function ($q) {
                $q->has('rounds', '<', 13)
                    ->whereDoesntHave('events', fn ($eq) => $eq->where('event_type', 'match_end'));
            });
    }

    /**
     * "Ya terminó de verdad, notificala" — más estricto que reachedConclusion()
     * sola (2026-09-01, bug real: cod2:notify-discord-matches notificaba una
     * partida a los 2 segundos de arrancar, con "Duración: 0 min", porque
     * reachedConclusion() solo mira cantidad de rondas/evento match_end, sin
     * mirar si el parser todavía la sigue jugando — una partida que va a
     * overtime (empate 12-12) sigue repartiendo winner_guids reales pasada la
     * ronda 13 mientras SIGUE en curso, así que "13+ rondas" sola no alcanza
     * como señal de "esto ya terminó". stillCurrent() (el mismo puntero de
     * log_parser_state.current_match_id que ya usa abandonedWithoutConclusion())
     * es la señal real de "el parser la sigue rastreando" — mientras lo sea,
     * nunca se notifica, sin importar cuántas rondas lleve.
     */
    public function scopeReadyToNotify($query)
    {
        $currentMatchIds = LogParserState::whereNotNull('current_match_id')->pluck('current_match_id');

        return $query->whereNotIn('id', $currentMatchIds)
            ->where(fn ($q) => $q->reachedConclusion());
    }

    /**
     * Que partidas cuentan para una temporada dada -- $seasonId es un id real o el
     * string literal 'all' (todas las temporadas juntas). Las partidas abandonadas
     * sin resultado real se excluyen SIEMPRE, sin importar la temporada (incluido
     * en 'all') -- mismo criterio que ya usa StatsRecalculator para los
     * acumuladores de por vida.
     */
    public function scopeForSeason($query, $seasonId)
    {
        if ($seasonId !== 'all') {
            $query->where('season_id', $seasonId);
        }

        return $query->whereNotIn('id', static::abandonedWithoutConclusion()->pluck('id'));
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
