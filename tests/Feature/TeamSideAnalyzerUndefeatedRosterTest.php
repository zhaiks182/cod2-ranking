<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Round;
use App\Support\TeamSideAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug real en vivo (2026-08-24, partida id=99, Burgundy): un roster que
 * arranca ganando todas las rondas (3-0 hasta el momento) hacía que
 * clusterRoundWinners() nunca asignara ninguna ronda al cluster B -- no hay
 * ningún guid real del roster perdedor todavía, así que no hay señal contra
 * la que comparar. La función exigía que AMBOS clusters tuvieran al menos
 * una ronda para devolver algo, así que devolvía null entero: el marcador
 * final y el desglose axis/allies desaparecían de la página de la partida
 * mientras un equipo la estaba barriendo, justo cuando más sentido tendría
 * mostrar "3-0".
 */
class TeamSideAnalyzerUndefeatedRosterTest extends TestCase
{
    use RefreshDatabase;

    private function makeRound(array $winnerGuids): Round
    {
        return Round::create([
            'map' => 'mp_burgundy_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
            'winner_guids' => $winnerGuids,
        ]);
    }

    public function test_clustering_still_returns_a_score_when_one_roster_has_not_lost_a_round_yet(): void
    {
        $rounds = collect();

        $winners = [46272534, -1896022996, -1691447606, -1862125167, -141511537, 1488894173, -283038432, -1119447689];

        for ($i = 0; $i < 3; $i++) {
            $rounds->push($this->makeRound($winners));
        }

        $clusters = TeamSideAnalyzer::clusterRoundWinners($rounds);

        $this->assertNotNull($clusters, 'clusterRoundWinners() must not return null just because one roster has 0 round wins so far.');
        $this->assertSame(3, max($clusters['A']['score'], $clusters['B']['score']));
        $this->assertSame(0, min($clusters['A']['score'], $clusters['B']['score']));
    }

    public function test_winning_roster_guids_resolves_correctly_when_the_other_side_is_undefeated(): void
    {
        $rounds = collect();
        $winners = [111, 222, 333];

        for ($i = 0; $i < 4; $i++) {
            $rounds->push($this->makeRound($winners));
        }

        $winningGuids = TeamSideAnalyzer::winningRosterGuids($rounds);

        $this->assertNotNull($winningGuids);
        $this->assertEqualsCanonicalizing($winners, $winningGuids);
    }

    public function test_side_scores_infers_the_opposite_side_when_the_losing_roster_is_still_unknown(): void
    {
        $winners = [111, 222, 333];
        $winningPlayers = collect($winners)->map(fn ($guid) => Player::create([
            'guid' => $guid,
            'last_name' => "p{$guid}",
            'last_name_plain' => "p{$guid}",
        ]));

        $rounds = collect();
        for ($i = 0; $i < 3; $i++) {
            $rounds->push($this->makeRound($winners));
        }

        $sideByPlayerId = $winningPlayers->mapWithKeys(fn ($p) => [$p->id => 'allies'])->all();

        $result = TeamSideAnalyzer::sideScores($rounds, $sideByPlayerId);

        $this->assertSame('allies', $result['winning']);
        $this->assertSame(3, $result['allies']);
        $this->assertSame(0, $result['axis']);
    }
}
