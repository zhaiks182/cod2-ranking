<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\MatchEvent;
use App\Models\Server;
use App\Support\KillAggregator;
use App\Support\TeamSideAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();

        $from = $request->query('from');
        $to = $request->query('to');
        $usingDateFilter = (bool) ($from || $to);

        // Backfilled matches have no real date (see is_backfilled migration) — a date
        // filter can never honestly match them, and they're shown as a separate
        // "imported" list rather than under a (fake) date heading.
        $backfilled = $usingDateFilter
            ? collect()
            : GameMatch::where('server_id', $server?->id)->where('is_backfilled', true)
                ->orderByDesc('id')->withCount('kills')->get();

        $matches = GameMatch::where('server_id', $server?->id)->where('is_backfilled', false)
            ->when($from, fn ($q) => $q->where('started_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to, fn ($q) => $q->where('started_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('started_at')
            ->withCount('kills')
            ->with('rounds:id,match_id,winner_guids')
            ->paginate(20)
            ->withQueryString();

        $grouped = $matches->getCollection()->groupBy(fn ($m) => $m->started_at->toDateString());

        return view('matches.index', compact('servers', 'server', 'matches', 'grouped', 'backfilled', 'from', 'to'));
    }

    public function show(GameMatch $match)
    {
        $match->load('server');

        $leaderboard = KillAggregator::aggregate(
            fn () => Kill::query()->where('kills.match_id', $match->id)
        );

        $rounds = $match->rounds()->orderBy('id')->get();
        $finalScore = $match->setRelation('rounds', $rounds)->final_score;

        // Two reference points for a MR12 series: the first real kill of round 1
        // (confirms the match genuinely started, as opposed to ready-up/aim-trainer
        // noise) and the first kill of the round right after the "HalfTime;" event
        // the server itself sends — replaces the old "assume round 13" heuristic
        // (still used as a fallback for matches parsed before this event was
        // captured, ~2026-08-13 and earlier).
        $firstRound = $rounds->first();

        $halftimeEvent = MatchEvent::where('match_id', $match->id)->where('event_type', 'halftime')->first();
        $halftimeRound = $halftimeEvent
            ? $rounds->where('id', '!=', $halftimeEvent->round_id)->where('started_at', '>=', $halftimeEvent->occurred_at)->sortBy('id')->first()
            : $rounds->get(12); // 0-indexed fallback — the 13th round

        $matchStartKill = $firstRound
            ? Kill::where('round_id', $firstRound->id)->orderBy('id')->first()
            : null;
        $halftimeKill = $halftimeRound
            ? Kill::where('round_id', $halftimeRound->id)->orderBy('id')->first()
            : null;

        $matchEvents = MatchEvent::where('match_id', $match->id)
            ->whereIn('event_type', ['overtime', 'timeout_call', 'timeout_cancel', 'bash_call'])
            ->orderBy('occurred_at')->get();
        $overtimeEvent = $matchEvents->firstWhere('event_type', 'overtime');
        $timeoutEvents = $matchEvents->whereIn('event_type', ['timeout_call', 'timeout_cancel', 'bash_call'])->values();

        // "Bash" here is the melee kill (mod=MOD_MELEE), not the bash_call; log event
        // above (a red herring — see SpecialtyController::bashCalls()) — whoever has
        // the most in THIS match, shown as a quick callout next to the other badges.
        $topBash = $leaderboard->where('bash', '>', 0)->sortByDesc('bash')->first();
        $topHeadshots = $leaderboard->where('headshots', '>', 0)->sortByDesc('headshots')->first();
        $topGrenades = $leaderboard->where('grenade_kills', '>', 0)->sortByDesc('grenade_kills')->first();

        [$axisRows, $alliesRows, $sideByPlayerId] = TeamSideAnalyzer::splitByCurrentSide($rounds, $leaderboard);
        $sideScores = TeamSideAnalyzer::sideScores($rounds, $sideByPlayerId);

        // "Ganador" only means something once the map is actually done — while it's
        // still being played, whoever is ahead right now isn't the winner yet.
        if (! $match->ended_at) {
            $sideScores['winning'] = null;
        }

        $chatMessages = ChatMessage::where('match_id', $match->id)->where('channel', 'public')->orderBy('occurred_at')->get();

        // Team chat ("sayteam;") carries no side of its own — bucketed here by each
        // message's player via the same $sideByPlayerId map the axis/allies panels
        // above use, so "which side said this" always matches what the page already
        // shows as that player's side. A message from a player with no resolved side
        // (never in a Kill; row this match) can't be placed on either panel and is
        // dropped rather than guessed.
        $teamChat = ChatMessage::where('match_id', $match->id)->where('channel', 'team')->orderBy('occurred_at')->get();
        $axisChat = $teamChat->filter(fn ($msg) => $msg->player_id && ($sideByPlayerId[$msg->player_id] ?? null) === 'axis')->values();
        $alliesChat = $teamChat->filter(fn ($msg) => $msg->player_id && ($sideByPlayerId[$msg->player_id] ?? null) === 'allies')->values();

        return view('matches.show', compact('match', 'leaderboard', 'rounds', 'finalScore', 'matchStartKill', 'halftimeKill', 'axisRows', 'alliesRows', 'sideScores', 'chatMessages', 'axisChat', 'alliesChat', 'overtimeEvent', 'timeoutEvents', 'topBash', 'topHeadshots', 'topGrenades'));
    }
}
