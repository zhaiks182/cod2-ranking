<?php

namespace Tests\Feature;

use App\Models\Round;
use App\Support\TeamSideAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug real en vivo (2026-08-24): un jugador humano contra bots (los bots
 * siempre reportan guid 0, indistinguibles entre sí) terminó una partida
 * 13-1, pero la página de la partida no mostraba ni el marcador final ni el
 * ganador. Causa: clusterRoundWinners() agrupa rondas por overlap de guids
 * contra un roster de referencia -- pero como el guid 0 aparece en TODAS las
 * rondas (los bots llenan ambos lados), cualquier ronda "solapa" con la
 * referencia A a través de esos ceros compartidos, así que el cluster B
 * nunca recibe una sola ronda y la función devuelve null.
 */
class TeamSideAnalyzerBotClusteringTest extends TestCase
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

    public function test_human_vs_bots_match_with_one_bot_only_round_still_clusters_correctly(): void
    {
        $rounds = collect();

        // 12 rounds where the human (17665482) and their bot allies win.
        for ($i = 0; $i < 12; $i++) {
            $rounds->push($this->makeRound([17665482, 0, 0, 0, 0, 0]));
        }

        // One round the human's team loses -- the winning roster is entirely
        // bots (guid 0), no real player identity in the winner list at all.
        $rounds->push($this->makeRound([0, 0, 0, 0, 0]));

        // Final round: human's team wins again, clinching 13.
        $rounds->push($this->makeRound([17665482, 0, 0, 0, 0, 0]));

        $clusters = TeamSideAnalyzer::clusterRoundWinners($rounds);

        $this->assertNotNull($clusters, 'clusterRoundWinners() must not return null for a human-vs-bots match.');
        $this->assertSame(13, max($clusters['A']['score'], $clusters['B']['score']));
        $this->assertSame(1, min($clusters['A']['score'], $clusters['B']['score']));

        $winningKey = $clusters['A']['score'] > $clusters['B']['score'] ? 'A' : 'B';
        $this->assertTrue($clusters[$winningKey]['guids']->contains(17665482));
    }

    public function test_human_vs_human_clustering_is_unaffected(): void
    {
        // Regression guard: no guid-0 bots involved, must behave exactly as
        // before the fix. Rounds alternate winners (as a real close match
        // would) so both rosters accumulate score before either reaches the
        // 13-round cutoff -- a block of 13 then 9 would break out of the loop
        // the instant the first roster hits 13, never even seeing the second.
        $rounds = collect();
        $pattern = [true, true, false, true, false, true, true, false, true, false, true, false, true, true, false, true, false, true, false, true, false, true];

        foreach ($pattern as $firstWins) {
            $rounds->push($this->makeRound($firstWins ? [111, 222] : [333, 444]));
        }

        $clusters = TeamSideAnalyzer::clusterRoundWinners($rounds);

        $this->assertNotNull($clusters);
        $this->assertSame(13, max($clusters['A']['score'], $clusters['B']['score']));
        $this->assertSame(9, min($clusters['A']['score'], $clusters['B']['score']));
    }
}
