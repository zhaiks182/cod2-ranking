<?php

namespace App\Support;

use App\Models\Kill;
use App\Models\MatchEvent;
use App\Models\Player;
use Illuminate\Support\Collection;

class TeamSideAnalyzer
{
    /**
     * Groups every round's winner_guids into the two persistent rosters that played
     * the match (not sides — those swap at halftime, rosters don't). A round can't
     * be matched by exact guid-set equality, since players connecting/disconnecting
     * mid-round change the set even though it's still "the same team", so each round
     * is classified by overlap against a REFERENCE roster per cluster.
     *
     * That reference used to be frozen at round 1 forever (compare every round to
     * "who won round 1"), which drifts wrong late in a match once enough
     * connects/disconnects have happened — confirmed on a real 22-round match where
     * the last round alone got misclassified (true final score was 13-9, this
     * produced 12-10) purely because round 22's roster had thinned out enough to no
     * longer clearly overlap with round 1's. Instead, each cluster's reference is
     * kept fresh — updated to whichever round most recently landed in it — so
     * classification tracks *gradual* roster drift instead of comparing everything
     * back to a single early (and increasingly stale) snapshot.
     *
     * @return array{A: array{score: int, guids: Collection}, B: array{score: int, guids: Collection}}|null
     */
    public static function clusterRoundWinners(Collection $rounds): ?array
    {
        // The real end of a match is the `match_end` log event, not "whoever hits 13
        // first" — a match tied 12-12 goes to overtime and keeps handing out real
        // winner_guids past 13 (confirmed on a real match that finished 16-13 in OT,
        // which a flat "stop at 13" cutoff truncated to a false 13-12). Trim to the
        // round match_end actually landed on when we have it; only matches parsed
        // before match_events existed (pre-2026-08-13) lack the event and fall back
        // to the old count-based cutoff below.
        $matchId = $rounds->first()?->match_id;
        $endRoundId = $matchId
            ? MatchEvent::where('match_id', $matchId)->where('event_type', 'match_end')->value('round_id')
            : null;

        if ($endRoundId) {
            $rounds = $rounds->filter(fn ($round) => $round->id <= $endRoundId);
        }

        $withWinners = $rounds->pluck('winner_guids')->filter()->values();

        if ($withWinners->count() < 2) {
            return null;
        }

        $clusters = [
            'A' => ['score' => 0, 'guids' => collect()],
            'B' => ['score' => 0, 'guids' => collect()],
        ];
        $reference = [];
        $lastKey = null;

        foreach ($withWinners as $guids) {
            $guids = collect($guids);

            // Bots all report guid 0 (see ParseCod2Log::upsertPlayer()) and are
            // indistinguishable from each other. With bots filling empty slots on
            // both rosters, guid 0 shows up in EVERY round's winner list no matter
            // which roster actually won — confirmed live (2026-08-24) on a real
            // 1-human-vs-bots match, where this made every round's winner_guids
            // "overlap" the same reference roster through the shared zeros, so
            // cluster B never got a single round and the whole function returned
            // null (final score and winner silently disappeared from the match
            // page for that entire match). Real guids are the only reliable
            // clustering signal; bots contribute nothing to it.
            $realGuids = $guids->reject(fn ($g) => $g === 0);

            if (! isset($reference['A'])) {
                $key = 'A';
            } elseif ($realGuids->isEmpty()) {
                // No real player in this round's winning roster at all (an
                // all-bot roster beat the other) — no guid signal to compare
                // against, but a round only ever has one winner, so it's simply
                // the other roster from whichever won the round before.
                $key = $lastKey === 'A' ? 'B' : 'A';
            } elseif (! isset($reference['B'])) {
                // B has no sighting yet — same overlap-vs-outside test the original
                // algorithm used, just against A's latest roster instead of round 1's.
                $overlapA = $realGuids->intersect($reference['A'])->count();
                $overlapOutsideA = $realGuids->diff($reference['A'])->count();
                $key = $overlapA >= $overlapOutsideA ? 'A' : 'B';
            } else {
                $overlapA = $realGuids->intersect($reference['A'])->count();
                $overlapB = $realGuids->intersect($reference['B'])->count();
                $key = $overlapA >= $overlapB ? 'A' : 'B';
            }

            $clusters[$key]['score']++;
            $clusters[$key]['guids'] = $clusters[$key]['guids']->merge($guids);
            if ($realGuids->isNotEmpty()) {
                $reference[$key] = $realGuids;
            } elseif (! isset($reference[$key])) {
                $reference[$key] = collect();
            }
            $lastKey = $key;

            // Fallback only: matches with a captured match_end already got trimmed to
            // their true last round above, so this never fires for them (needed — a
            // match tied 12-12 goes to overtime and keeps handing out real wins past
            // 13, see the match_end comment above). For older matches with no
            // match_end event, this is the old heuristic — MR12 ends the instant
            // either roster reaches 13 wins, and a Round row with real winner_guids
            // after that is ready-up/lobby noise (a stray RoundStart;/Winners; during
            // the post-match celebration screen), the same class of bug as the
            // aim-trainer kills fixed earlier (see ParseCod2Log's recordKill()).
            // Confirmed on a real match: its 22nd/last round had valid-looking
            // winner_guids for the *losing* roster, one round after the winning
            // roster had already legitimately reached 13.
            if (! $endRoundId && $clusters[$key]['score'] >= 13) {
                break;
            }
        }

        if ($clusters['A']['score'] === 0 || $clusters['B']['score'] === 0) {
            return null;
        }

        return $clusters;
    }

    /**
     * The guids of the roster that won a match — same clustering as final_score,
     * returning the winning cluster's guids directly rather than a score. Null if
     * there isn't enough round data to call a winner, or it's tied.
     */
    public static function winningRosterGuids(Collection $rounds): ?array
    {
        $clusters = self::clusterRoundWinners($rounds);

        if (! $clusters || $clusters['A']['score'] === $clusters['B']['score']) {
            return null;
        }

        $winningKey = $clusters['A']['score'] > $clusters['B']['score'] ? 'A' : 'B';

        return $clusters[$winningKey]['guids']->unique()->values()->all();
    }

    /**
     * Splits a leaderboard collection into axis/allies panels using each player's
     * *most recently observed* side — sides swap at halftime, so this is a snapshot
     * (matches what the in-game scoreboard shows right now for a live match, or how
     * the match ended for a finished one), not a whole-match label. Looking at only
     * the single latest round misses anyone who survived it without getting a kill or
     * dying (they just never appear in that round's Kill; lines) — walking rounds
     * newest-to-oldest and keeping the first (i.e. most recent) side seen per player
     * covers everyone who has a kill or death anywhere in scope, same set as the
     * leaderboard. Rounds before the attacker_team/victim_team columns existed have
     * none of this data, so older rounds simply contribute nothing (empty result).
     *
     * @param  Collection  $rounds  Rounds in scope (must have an 'id' on each row)
     * @param  Collection  $leaderboard  Rows from KillAggregator::aggregate() (each with ->player and ->teamkills)
     * @return array{0: Collection, 1: Collection, 2: array<int, string>} [axisRows, alliesRows, sideByPlayerId]
     */
    public static function splitByCurrentSide(Collection $rounds, Collection $leaderboard): array
    {
        $roundIds = $rounds->pluck('id')->reverse()->values();

        if ($roundIds->isEmpty()) {
            return [collect(), collect(), []];
        }

        $kills = Kill::whereIn('round_id', $roundIds)->whereNotNull('attacker_team')
            ->get(['round_id', 'attacker_player_id', 'attacker_team', 'victim_player_id', 'victim_team'])
            ->groupBy('round_id');

        $sideByPlayerId = [];
        foreach ($roundIds as $roundId) {
            foreach ($kills->get($roundId, collect()) as $kill) {
                if ($kill->attacker_player_id && ! isset($sideByPlayerId[$kill->attacker_player_id])) {
                    $sideByPlayerId[$kill->attacker_player_id] = $kill->attacker_team;
                }
                if ($kill->victim_player_id && ! isset($sideByPlayerId[$kill->victim_player_id])) {
                    $sideByPlayerId[$kill->victim_player_id] = $kill->victim_team;
                }
            }
        }

        $grouped = $leaderboard->groupBy(fn ($row) => $sideByPlayerId[$row->player->id] ?? 'unknown');

        return [$grouped->get('axis', collect()), $grouped->get('allies', collect()), $sideByPlayerId];
    }

    /**
     * Round-win tally per *current* side (axis/allies), plus which one won — same
     * clustering as final_score (round wins grouped by roster, not side, since sides
     * swap at halftime), with each roster cluster cross-referenced against
     * $sideByPlayerId to answer "which of the two panels shown right now has how
     * many round wins".
     *
     * @return array{axis: ?int, allies: ?int, winning: ?string}
     */
    public static function sideScores(Collection $rounds, array $sideByPlayerId): array
    {
        $empty = ['axis' => null, 'allies' => null, 'winning' => null];

        $clusters = self::clusterRoundWinners($rounds);

        if (! $clusters) {
            return $empty;
        }

        $sideOfCluster = function ($guids) use ($sideByPlayerId) {
            $playerIds = Player::whereIn('guid', $guids->unique())->pluck('id');
            $votes = ['axis' => 0, 'allies' => 0];
            foreach ($playerIds as $playerId) {
                // attacker_team/victim_team on kills isn't limited to axis/allies (e.g.
                // 'spectator' shows up too) — only tally the two sides this vote is
                // deciding between, anything else is simply not a vote either way.
                $side = $sideByPlayerId[$playerId] ?? null;
                if (isset($votes[$side])) {
                    $votes[$side]++;
                }
            }

            return $votes['axis'] === $votes['allies'] ? null : ($votes['axis'] > $votes['allies'] ? 'axis' : 'allies');
        };

        $sideA = $sideOfCluster($clusters['A']['guids']);
        $sideB = $sideOfCluster($clusters['B']['guids']);

        if (! $sideA || ! $sideB || $sideA === $sideB) {
            return $empty;
        }

        $scores = [$sideA => $clusters['A']['score'], $sideB => $clusters['B']['score']];
        $winning = $scores['axis'] > $scores['allies'] ? 'axis' : ($scores['allies'] > $scores['axis'] ? 'allies' : null);

        return ['axis' => $scores['axis'], 'allies' => $scores['allies'], 'winning' => $winning];
    }
}
