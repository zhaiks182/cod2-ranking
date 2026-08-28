<?php

namespace Tests\Feature;

use App\Models\Round;
use App\Support\TeamSideAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido del dueño (2026-08-30): /partidas mostraba el marcador numerico
 * ("19-17") sin decir a que lado (axis/allies) corresponde cada numero.
 * winningSideForMatch() es la version liviana de sideScores() usada para la
 * lista de partidas -- vota winningRosterGuids() contra el ultimo lado
 * observado de cada guid, sin construir el leaderboard completo del match.
 */
class TeamSideAnalyzerWinningSideForMatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeRound(array $winnerGuids): Round
    {
        return Round::create([
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
            'winner_guids' => $winnerGuids,
        ]);
    }

    private function kill(int $matchId, ?int $attackerGuid, ?string $attackerTeam, ?int $victimGuid, ?string $victimTeam): object
    {
        return (object) [
            'match_id' => $matchId,
            'attacker_guid' => $attackerGuid,
            'attacker_team' => $attackerTeam,
            'victim_guid' => $victimGuid,
            'victim_team' => $victimTeam,
        ];
    }

    public function test_returns_axis_when_the_winning_roster_played_axis(): void
    {
        $rounds = collect();
        for ($i = 0; $i < 13; $i++) {
            $rounds->push($this->makeRound([111, 222]));
        }
        for ($i = 0; $i < 5; $i++) {
            $rounds->push($this->makeRound([333, 444]));
        }

        $kills = collect([
            $this->kill(1, 111, 'axis', 333, 'allies'),
            $this->kill(1, 222, 'axis', 444, 'allies'),
        ]);

        $side = TeamSideAnalyzer::winningSideForMatch($rounds, $kills);

        $this->assertSame('axis', $side);
    }

    public function test_returns_allies_when_the_winning_roster_played_allies(): void
    {
        $rounds = collect();
        for ($i = 0; $i < 13; $i++) {
            $rounds->push($this->makeRound([111, 222]));
        }
        for ($i = 0; $i < 5; $i++) {
            $rounds->push($this->makeRound([333, 444]));
        }

        $kills = collect([
            $this->kill(1, 111, 'allies', 333, 'axis'),
            $this->kill(1, 222, 'allies', 444, 'axis'),
        ]);

        $side = TeamSideAnalyzer::winningSideForMatch($rounds, $kills);

        $this->assertSame('allies', $side);
    }

    public function test_returns_null_without_a_determinable_winner(): void
    {
        $rounds = collect([$this->makeRound([111])]);

        $side = TeamSideAnalyzer::winningSideForMatch($rounds, collect());

        $this->assertNull($side);
    }

    public function test_bot_guids_in_the_winning_roster_are_not_counted_as_votes(): void
    {
        // One real round seeds the human roster (cluster A); the 13 all-bot
        // rounds that follow have no real guid to cluster by, so they're
        // classified as the opposite roster (cluster B) -- a bot-only roster
        // that ends up winning the match overall (13 vs 1).
        $rounds = collect([$this->makeRound([555, 666])]);
        for ($i = 0; $i < 13; $i++) {
            $rounds->push($this->makeRound([0, 0, 0]));
        }

        // No real-guid kills for the winning (all-bot) roster at all.
        $kills = collect([
            $this->kill(1, 555, 'axis', 999, 'allies'),
        ]);

        $side = TeamSideAnalyzer::winningSideForMatch($rounds, $kills);

        $this->assertNull($side);
    }

    public function test_uses_the_most_recently_observed_side_per_guid(): void
    {
        $rounds = collect();
        for ($i = 0; $i < 13; $i++) {
            $rounds->push($this->makeRound([111]));
        }
        for ($i = 0; $i < 5; $i++) {
            $rounds->push($this->makeRound([222]));
        }

        // Player 111 started on allies (halftime swap) then finished on axis --
        // the LAST entry (id order) must win, not the first.
        $kills = collect([
            $this->kill(1, 111, 'allies', 222, 'axis'),
            $this->kill(1, 111, 'axis', 222, 'allies'),
        ]);

        $side = TeamSideAnalyzer::winningSideForMatch($rounds, $kills);

        $this->assertSame('axis', $side);
    }
}
