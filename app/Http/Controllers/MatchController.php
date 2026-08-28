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
            ->visibleInListing()
            ->when($from, fn ($q) => $q->where('started_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to, fn ($q) => $q->where('started_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('started_at')
            ->withCount('kills')
            ->with('rounds:id,match_id,winner_guids')
            ->paginate(20)
            ->withQueryString();

        // Quien gano (axis/allies) de cada partida de la pagina (2026-08-30, a
        // pedido del dueño -- /partidas solo mostraba el marcador numerico "19-17",
        // sin decir a que lado corresponde cada numero). Una sola query batcheada
        // para las 20 partidas de la pagina, no una por fila -- ver
        // TeamSideAnalyzer::winningSideForMatch() para el detalle de por que no
        // hace falta construir el leaderboard completo de cada partida solo para
        // esto.
        $killsByMatch = Kill::whereIn('match_id', $matches->getCollection()->pluck('id'))
            ->whereNotNull('attacker_team')
            ->orderBy('id')
            ->get(['match_id', 'attacker_guid', 'attacker_team', 'victim_guid', 'victim_team'])
            ->groupBy('match_id');

        $matches->getCollection()->each(function ($match) use ($killsByMatch) {
            $match->winning_side = TeamSideAnalyzer::winningSideForMatch($match->rounds, $killsByMatch->get($match->id, collect()));
        });

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

        $roundDetails = $this->buildRoundDetails($match, $rounds);

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

        return view('matches.show', compact('match', 'leaderboard', 'rounds', 'roundDetails', 'finalScore', 'matchStartKill', 'halftimeKill', 'axisRows', 'alliesRows', 'sideScores', 'chatMessages', 'axisChat', 'alliesChat', 'overtimeEvent', 'timeoutEvents', 'topBash', 'topHeadshots', 'topGrenades'));
    }

    /**
     * Grid por ronda (pedido de un jugador, 2026-08-28: "quien mato a quien y con
     * que arma, o si hubo un clutch" para poder revisar jugadas raras o sacar
     * clips). Reusa la MISMA definicion de clutch que
     * SpecialtyController::clutches() (roster completo del round.winner_guids
     * menos quien murio ESE round, un solo sobreviviente en un roster de 3+) --
     * ahi ya vive por-jugador-y-temporada; aca se aplica ronda por ronda, dentro
     * de una sola partida, sin duplicar la logica en otro lado (mismo calculo,
     * dos alcances distintos).
     */
    private function buildRoundDetails($match, $rounds)
    {
        $killsByRound = Kill::where('match_id', $match->id)
            ->with(['attacker', 'victim'])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('round_id');

        return $rounds->values()->map(function ($round, $i) use ($killsByRound) {
            $kills = $killsByRound->get($round->id, collect());

            $roster = collect($round->winner_guids ?? []);
            $deaths = $kills->pluck('victim_guid')->filter()->unique();
            $survivors = $roster->diff($deaths);
            $clutchGuid = ($roster->count() >= 3 && $survivors->count() === 1) ? $survivors->first() : null;

            return (object) [
                'number' => $i + 1,
                'round' => $round,
                'kills' => $kills,
                'clutchGuid' => $clutchGuid,
                'winningSide' => $this->roundWinningSide($roster, $kills),
            ];
        });
    }

    /**
     * Linea de tiempo de rondas (pedido de un jugador, 2026-08-28): que lado (axis/
     * allies) gano CADA ronda -- no alcanza con el lado "actual" de un jugador
     * (TeamSideAnalyzer::splitByCurrentSide(), que es un snapshot del match entero,
     * ya usado para las tablas axis/allies de arriba) porque los lados cambian de
     * bando en el entretiempo; hace falta el lado real DENTRO de esa ronda especifica.
     * Mismo patron de votacion por mayoria que TeamSideAnalyzer::sideScores() usa
     * para todo el match, aca acotado a las kills de una sola ronda: cualquier kill
     * de esa ronda donde el atacante O la victima este en el roster ganador aporta
     * un voto por el lado (axis/allies) que esa persona tenia en ESA kill puntual.
     */
    private function roundWinningSide($roster, $kills): ?string
    {
        $votes = ['axis' => 0, 'allies' => 0];

        foreach ($kills as $kill) {
            if ($roster->contains($kill->attacker_guid) && isset($votes[$kill->attacker_team])) {
                $votes[$kill->attacker_team]++;
            }
            if ($roster->contains($kill->victim_guid) && isset($votes[$kill->victim_team])) {
                $votes[$kill->victim_team]++;
            }
        }

        if ($votes['axis'] === $votes['allies']) {
            return null;
        }

        return $votes['axis'] > $votes['allies'] ? 'axis' : 'allies';
    }
}
