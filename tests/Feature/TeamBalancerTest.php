<?php

namespace Tests\Feature;

use App\Support\TeamBalancer;
use Tests\TestCase;

/**
 * TeamBalancer::suggest() arma 2 equipos a partir de la lista de conectados
 * de Cod2RconClient::status() (slot/score/ping/guid/name/ip) y el rango
 * calculado por PlayerRankCalculator -- probado aca contra colecciones de
 * rango armadas a mano, sin tocar la base de datos, ya que la funcion no
 * depende de Eloquent para nada.
 */
class TeamBalancerTest extends TestCase
{
    private function rank(int $guid, float $score, string $rango): object
    {
        return (object) ['guid' => $guid, 'score' => $score, 'rango' => $rango];
    }

    public function test_fewer_than_min_players_returns_not_enough(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'a'],
            ['guid' => 2, 'name' => 'b'],
            ['guid' => 3, 'name' => 'c'],
        ];

        $result = TeamBalancer::suggest($players, collect());

        $this->assertFalse($result->enough);
        $this->assertSame(3, $result->eligible);
        $this->assertCount(0, $result->teamA);
        $this->assertCount(0, $result->teamB);
    }

    public function test_bots_are_excluded_and_counted_separately(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'p1'],
            ['guid' => 2, 'name' => 'p2'],
            ['guid' => 3, 'name' => 'p3'],
            ['guid' => 4, 'name' => 'p4'],
            ['guid' => 0, 'name' => 'bot1'],
            ['guid' => 0, 'name' => 'bot2'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 90, 'A'),
            2 => $this->rank(2, 70, 'B'),
            3 => $this->rank(3, 40, 'D'),
            4 => $this->rank(4, 10, 'E'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);

        $this->assertTrue($result->enough);
        $this->assertSame(2, $result->bots);
        $this->assertSame(4, $result->eligible);
    }

    public function test_snake_draft_balances_total_score_and_keeps_min_two_per_team(): void
    {
        // Scores 100, 80, 60, 40 -- snake draft (A,B,B,A) should give
        // A = {100, 40} = 140 and B = {80, 60} = 140, a perfect split.
        $players = [
            ['guid' => 1, 'name' => 'best'],
            ['guid' => 2, 'name' => 'second'],
            ['guid' => 3, 'name' => 'third'],
            ['guid' => 4, 'name' => 'worst'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 100, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 60, 'C'),
            4 => $this->rank(4, 40, 'D'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);

        $this->assertTrue($result->enough);
        $this->assertCount(2, $result->teamA);
        $this->assertCount(2, $result->teamB);
        $this->assertSame(140.0, $result->scoreA);
        $this->assertSame(140.0, $result->scoreB);
        $this->assertSame(['best', 'worst'], $result->teamA->pluck('name')->all());
        $this->assertSame(['second', 'third'], $result->teamB->pluck('name')->all());
    }

    public function test_unranked_connected_player_gets_default_neutral_score(): void
    {
        $players = [
            ['guid' => 1, 'name' => 'ranked1'],
            ['guid' => 2, 'name' => 'ranked2'],
            ['guid' => 3, 'name' => 'ranked3'],
            ['guid' => 99, 'name' => 'brand-new-player'],
        ];

        $ranks = collect([
            1 => $this->rank(1, 90, 'A'),
            2 => $this->rank(2, 80, 'B'),
            3 => $this->rank(3, 20, 'E'),
        ]);

        $result = TeamBalancer::suggest($players, $ranks);

        $newPlayer = $result->teamA->concat($result->teamB)->firstWhere('guid', 99);

        $this->assertNotNull($newPlayer);
        $this->assertNull($newPlayer->rango);
        $this->assertSame(TeamBalancer::DEFAULT_SCORE, $newPlayer->score);
    }
}
