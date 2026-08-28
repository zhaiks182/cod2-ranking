<?php

namespace Tests\Feature\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use App\Support\WinRateCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WinRateCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private Player $player;
    private Player $opponent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);

        $this->player = Player::create(['guid' => 500, 'last_name' => 'Player', 'last_name_plain' => 'Player']);
        $this->opponent = Player::create(['guid' => 999, 'last_name' => 'Opponent', 'last_name_plain' => 'Opponent']);
    }

    /**
     * @param  int[]  $winnerGuidsEachRound  Same winner_guids on all 13 rounds --
     *      enough for clusterRoundWinners() to call a 13-0 result.
     */
    private function makeMatch(array $winnerGuidsEachRound, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => 1,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => $map,
                'gametype' => 'sd',
                'started_at' => now(),
                'ended_at' => now(),
                'winner_guids' => $winnerGuidsEachRound,
            ]);
        }

        Kill::create([
            'round_id' => $match->rounds()->first()->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'mod' => 'MOD_RIFLE_BULLET',
            'damage' => 50,
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_win_counts_toward_win_rate(): void
    {
        $match = $this->makeMatch([$this->player->guid], $this->player, $this->opponent);

        $result = WinRateCalculator::forPlayer($this->player, collect([$match->id]));

        $this->assertSame(1, $result['played']);
        $this->assertSame(1, $result['wins']);
        $this->assertSame(100.0, $result['rate']);
    }

    public function test_loss_counts_toward_matches_played_but_not_wins(): void
    {
        $match = $this->makeMatch([$this->opponent->guid], $this->opponent, $this->player);

        $result = WinRateCalculator::forPlayer($this->player, collect([$match->id]));

        $this->assertSame(1, $result['played']);
        $this->assertSame(0, $result['wins']);
        $this->assertSame(0.0, $result['rate']);
    }

    public function test_rate_is_averaged_across_multiple_matches(): void
    {
        $won = $this->makeMatch([$this->player->guid], $this->player, $this->opponent);
        $lost = $this->makeMatch([$this->opponent->guid], $this->opponent, $this->player);

        $result = WinRateCalculator::forPlayer($this->player, collect([$won->id, $lost->id]));

        $this->assertSame(2, $result['played']);
        $this->assertSame(1, $result['wins']);
        $this->assertSame(50.0, $result['rate']);
    }

    public function test_match_without_a_determinable_winner_is_excluded_from_played(): void
    {
        // No winner_guids on any round -> clusterRoundWinners() can't call it,
        // winningRosterGuids() returns null -- must not count as played or lost.
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => 1,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $round = Round::create([
            'server_id' => $this->server->id,
            'match_id' => $match->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $this->player->id,
            'attacker_guid' => $this->player->guid,
            'attacker_name' => $this->player->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $this->opponent->id,
            'victim_guid' => $this->opponent->guid,
            'victim_name' => $this->opponent->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'mod' => 'MOD_RIFLE_BULLET',
            'damage' => 50,
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        $result = WinRateCalculator::forPlayer($this->player, collect([$match->id]));

        $this->assertSame(0, $result['played']);
        $this->assertSame(0, $result['wins']);
        $this->assertSame(0.0, $result['rate']);
    }

    public function test_matches_the_player_did_not_participate_in_are_ignored(): void
    {
        $match = $this->makeMatch([$this->opponent->guid], $this->opponent, $this->opponent);

        $result = WinRateCalculator::forPlayer($this->player, collect([$match->id]));

        $this->assertSame(0, $result['played']);
        $this->assertSame(0, $result['wins']);
    }

    public function test_by_map_breaks_down_played_and_won_per_map(): void
    {
        $toujaneWin = $this->makeMatch([$this->player->guid], $this->player, $this->opponent, 'mp_toujane_fix');
        $toujaneLoss = $this->makeMatch([$this->opponent->guid], $this->opponent, $this->player, 'mp_toujane_fix');
        $burgundyWin = $this->makeMatch([$this->player->guid], $this->player, $this->opponent, 'mp_burgundy_fix');

        $rows = WinRateCalculator::byMapForPlayer($this->player, collect([$toujaneWin->id, $toujaneLoss->id, $burgundyWin->id]));

        $toujane = $rows->firstWhere('map', 'mp_toujane');
        $burgundy = $rows->firstWhere('map', 'mp_burgundy');

        $this->assertNotNull($toujane);
        $this->assertSame(2, $toujane->played);
        $this->assertSame(1, $toujane->wins);
        $this->assertSame(50.0, $toujane->rate);

        $this->assertNotNull($burgundy);
        $this->assertSame(1, $burgundy->played);
        $this->assertSame(1, $burgundy->wins);
        $this->assertSame(100.0, $burgundy->rate);
    }

    public function test_by_map_merges_community_patch_variants_of_the_same_real_map(): void
    {
        // mp_toujane_fix and mp_toujane_bal are both community variants of the
        // same real map -- MapCatalog::normalize() collapses both to mp_toujane,
        // same criterion already used by mergeVariants() for "Mejores mapas".
        $fixVariant = $this->makeMatch([$this->player->guid], $this->player, $this->opponent, 'mp_toujane_fix');
        $balVariant = $this->makeMatch([$this->player->guid], $this->player, $this->opponent, 'mp_toujane_bal');

        $rows = WinRateCalculator::byMapForPlayer($this->player, collect([$fixVariant->id, $balVariant->id]));

        $this->assertSame(1, $rows->count());
        $this->assertSame('mp_toujane', $rows->first()->map);
        $this->assertSame(2, $rows->first()->played);
        $this->assertSame(2, $rows->first()->wins);
    }
}
