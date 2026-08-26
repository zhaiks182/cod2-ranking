<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerShowSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private Player $attacker;
    private Player $victim;

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

        $this->attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $this->victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
    }

    private function realMatchWithKill(int $seasonId, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
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
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $this->attacker->id,
            'attacker_guid' => $this->attacker->guid,
            'attacker_name' => $this->attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $this->victim->id,
            'victim_guid' => $this->victim->guid,
            'victim_name' => $this->victim->last_name,
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'mod' => 'MOD_RIFLE_BULLET',
            'damage' => 50,
            'is_headshot' => true,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_profile_without_season_param_shows_only_the_active_season(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', $this->attacker->guid));

        $response->assertOk();
        $player = $response->viewData('player');
        $this->assertSame(2, $player->kills_total); // solo Temporada 2 (activa)
        $this->assertSame(0, $player->deaths_total); // el attacker no murio en esas partidas
        $this->assertSame(100.0, $player->headshot_rate); // getHeadshotRateAttribute() recalcula sobre kills_total/headshots_total ya scopeados -- los 2 kills de la fixture son headshot
    }

    public function test_profile_with_season_all_shows_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', [$this->attacker->guid, 'season' => 'all']));

        $player = $response->viewData('player');
        $this->assertSame(2, $player->kills_total);
    }

    public function test_profile_map_stats_are_scoped_to_the_season(): void
    {
        $season = Season::current();
        $this->realMatchWithKill($season->id, 'mp_toujane_fix');
        $this->realMatchWithKill($season->id, 'mp_railyard');

        $response = $this->get(route('players.show', $this->attacker->guid));

        $mapStats = $response->viewData('player')->mapStats;
        $this->assertSame(2, $mapStats->count());
        // Check that one of the maps has 1 kill (they're both 1 kill each)
        $this->assertTrue($mapStats->contains(fn($s) => $s->kills === 1));
    }

    public function test_profile_recent_kills_are_scoped_to_the_season(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', $this->attacker->guid));

        $this->assertSame(1, $response->viewData('recentKills')->count());
    }
}
