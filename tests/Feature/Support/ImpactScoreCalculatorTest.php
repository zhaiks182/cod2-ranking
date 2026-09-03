<?php

namespace Tests\Feature\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\Round;
use App\Models\Server;
use App\Support\ImpactScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private GameMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $this->match = GameMatch::create(['server_id' => $this->server->id, 'season_id' => 1, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
    }

    private function matchIds(): \Illuminate\Support\Collection
    {
        return collect([$this->match->id]);
    }

    private function makeRound(array $winnerGuids): Round
    {
        return Round::create([
            'server_id' => $this->server->id, 'match_id' => $this->match->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => $winnerGuids,
        ]);
    }

    private function makeKill(Round $round, Player $attacker, Player $victim, string $occurredAt, bool $teamkill = false, bool $suicide = false): Kill
    {
        return Kill::create([
            'round_id' => $round->id, 'match_id' => $this->match->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => $teamkill ? 'allies' : 'axis',
            'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => $suicide, 'is_teamkill' => $teamkill,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_bomb_plant_and_defuse_award_points(): void
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'P', 'last_name_plain' => 'P']);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $this->match->id, 'bomb_plants' => 2, 'bomb_defuses' => 1]);

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        $this->assertSame(2 * 1.0 + 1 * 1.5, $points[$player->id]);
    }

    public function test_first_kill_of_the_round_earns_first_blood(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);
        $v = Player::create(['guid' => 3, 'last_name' => 'V', 'last_name_plain' => 'V']);
        $round = $this->makeRound([$a->guid, $b->guid]);
        $this->makeKill($round, $a, $v, '2026-09-02 10:00:00');
        $this->makeKill($round, $b, $v, '2026-09-02 10:00:05');

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        $this->assertSame(1.0, $points[$a->id]);
        $this->assertArrayNotHasKey($b->id, $points);
    }

    public function test_multi_kill_awards_only_the_highest_tier(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $v1 = Player::create(['guid' => 2, 'last_name' => 'V1', 'last_name_plain' => 'V1']);
        $v2 = Player::create(['guid' => 3, 'last_name' => 'V2', 'last_name_plain' => 'V2']);
        $v3 = Player::create(['guid' => 4, 'last_name' => 'V3', 'last_name_plain' => 'V3']);
        $round = $this->makeRound([$a->guid]);
        $this->makeKill($round, $a, $v1, '2026-09-02 10:00:00');
        $this->makeKill($round, $a, $v2, '2026-09-02 10:00:05');
        $this->makeKill($round, $a, $v3, '2026-09-02 10:00:10');

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        // 1.0 (primera sangre) + 2.0 (triple kill) = 3.0
        $this->assertSame(3.0, $points[$a->id]);
    }

    public function test_teamkills_and_suicides_never_earn_first_blood_or_multikill(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $mate = Player::create(['guid' => 2, 'last_name' => 'M', 'last_name_plain' => 'M']);
        $round = $this->makeRound([$a->guid, $mate->guid]);
        $this->makeKill($round, $a, $mate, '2026-09-02 10:00:00', teamkill: true);

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        $this->assertArrayNotHasKey($a->id, $points);
    }

    /**
     * Equipo de 3 (a, b, clutcher) vs 2 enemigos (e1, e2). Orden real:
     * e1 mata a a (primera sangre para e1), e2 mata a b -- ESE es el
     * instante en que el equipo del clutcher queda en 1, con e2 como unico
     * enemigo vivo en ese momento (1v1) -- clutcher remata a e2 y gana.
     */
    public function test_clutch_1v1_awards_the_correct_bonus(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);
        $clutcher = Player::create(['guid' => 3, 'last_name' => 'C', 'last_name_plain' => 'C']);
        $e1 = Player::create(['guid' => 4, 'last_name' => 'E1', 'last_name_plain' => 'E1']);
        $e2 = Player::create(['guid' => 5, 'last_name' => 'E2', 'last_name_plain' => 'E2']);
        $round = $this->makeRound([$a->guid, $b->guid, $clutcher->guid]);

        $this->makeKill($round, $a, $e1, '2026-09-02 10:00:00');
        $this->makeKill($round, $e2, $a, '2026-09-02 10:00:05');
        $this->makeKill($round, $e2, $b, '2026-09-02 10:00:10');
        $this->makeKill($round, $clutcher, $e2, '2026-09-02 10:00:15');

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        // $a: 1.0 (primera sangre, muere despues sin sumar mas).
        // $clutcher: un solo kill (sin multi-kill) + 1.5 de clutch 1v1.
        $this->assertSame(1.0, $points[$a->id]);
        $this->assertSame(1.5, $points[$clutcher->id]);
    }

    /**
     * Mismo tipo de escenario pero los DOS enemigos siguen vivos en el
     * instante en que el equipo del clutcher queda en 1 (1v2) -- tiene que
     * eliminar a ambos el solo, lo que ademas dispara el bono de doble kill
     * (esperado: son cosas relacionadas, no un bug).
     */
    public function test_clutch_1v2_awards_the_correct_bonus(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);
        $clutcher = Player::create(['guid' => 3, 'last_name' => 'C', 'last_name_plain' => 'C']);
        $e1 = Player::create(['guid' => 4, 'last_name' => 'E1', 'last_name_plain' => 'E1']);
        $e2 = Player::create(['guid' => 5, 'last_name' => 'E2', 'last_name_plain' => 'E2']);
        $round = $this->makeRound([$a->guid, $b->guid, $clutcher->guid]);

        $this->makeKill($round, $e1, $a, '2026-09-02 10:00:00');
        $this->makeKill($round, $e2, $b, '2026-09-02 10:00:05');
        $this->makeKill($round, $clutcher, $e1, '2026-09-02 10:00:10');
        $this->makeKill($round, $clutcher, $e2, '2026-09-02 10:00:15');

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        // 2.5 (clutch 1v2) + 1.0 (doble kill) = 3.5
        $this->assertSame(3.5, $points[$clutcher->id]);
    }

    public function test_no_clutch_bonus_when_the_round_ends_with_more_than_one_survivor(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);
        $e1 = Player::create(['guid' => 3, 'last_name' => 'E1', 'last_name_plain' => 'E1']);
        $round = $this->makeRound([$a->guid, $b->guid, Player::create(['guid' => 9, 'last_name' => 'X', 'last_name_plain' => 'X'])->guid]);
        $this->makeKill($round, $a, $e1, '2026-09-02 10:00:00');

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        $this->assertSame(1.0, $points[$a->id]); // solo primera sangre, sin clutch
    }

    public function test_a_roster_smaller_than_three_never_counts_as_a_clutch(): void
    {
        $clutcher = Player::create(['guid' => 1, 'last_name' => 'C', 'last_name_plain' => 'C']);
        $mate = Player::create(['guid' => 2, 'last_name' => 'M', 'last_name_plain' => 'M']);
        $e1 = Player::create(['guid' => 3, 'last_name' => 'E1', 'last_name_plain' => 'E1']);
        $round = $this->makeRound([$clutcher->guid, $mate->guid]); // roster de 2, no 3+
        $this->makeKill($round, $e1, $mate, '2026-09-02 10:00:00');
        $this->makeKill($round, $clutcher, $e1, '2026-09-02 10:00:05');

        $points = ImpactScoreCalculator::calculate($this->server->id, $this->matchIds());

        // La primera sangre real fue de $e1 (mato primero) -- sin clutch (roster<3),
        // el clutcher no gana ningun punto por su unica baja (no es multi-kill).
        $this->assertSame(1.0, $points[$e1->id]);
        $this->assertArrayNotHasKey($clutcher->id, $points);
    }
}
