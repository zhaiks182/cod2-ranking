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

    private function createKillWithWeapon(int $seasonId, Player $attacker, Player $victim, string $weapon, string $map = 'mp_toujane_fix'): Kill
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

        return Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'axis',
            'weapon' => $weapon,
            'mod' => 'MOD_RIFLE_BULLET',
            'damage' => 50,
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);
    }

    private function createTeamkill(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): Kill
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

        return Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $attacker->id,
            'attacker_guid' => $attacker->guid,
            'attacker_name' => $attacker->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $victim->id,
            'victim_guid' => $victim->guid,
            'victim_name' => $victim->last_name,
            'victim_team' => 'allies', // Same team = teamkill
            'weapon' => 'weapon_uzi',
            'mod' => 'MOD_RIFLE_BULLET',
            'damage' => 50,
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => true,
            'occurred_at' => now(),
        ]);
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

    /**
     * weaponBreakdown (2026-08-29, reemplaza "Últimas bajas") tiene que respetar
     * el mismo scope de temporada que ya tenía esa lista -- si contara las 2
     * kills (vieja+nueva temporada) en vez de solo 1, el desglose de armas
     * mentiría sobre lo que pasó en la temporada activa.
     */
    public function test_weapon_breakdown_is_scoped_to_the_season(): void
    {
        $oldSeason = Season::current();
        $this->realMatchWithKill($oldSeason->id);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', $this->attacker->guid));

        $weaponBreakdown = $response->viewData('weaponBreakdown');
        $this->assertSame(1, $weaponBreakdown->count());
        $this->assertSame(1, $weaponBreakdown->first()->kills);
    }

    public function test_favorite_weapon_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        // Create kill with AK in old season
        $this->createKillWithWeapon($oldSeason->id, $this->attacker, $this->victim, 'weapon_ak74');

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        // Create kill with MP44 in new season
        $this->createKillWithWeapon($newSeason->id, $this->attacker, $this->victim, 'weapon_mp44');

        $response = $this->get(route('players.show', $this->attacker->guid));

        $favoriteWeapon = $response->viewData('favoriteWeapon');
        // Should only reflect the active season's weapon (MP44), not AK74 from old season
        $this->assertNotNull($favoriteWeapon);
        $this->assertSame('weapon_mp44', $favoriteWeapon->weapon);
    }

    public function test_teamkill_count_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        // Create a teamkill in old season
        $this->createTeamkill($oldSeason->id, $this->attacker, $this->victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        // Create a regular kill in new season (not a teamkill)
        $this->realMatchWithKill($newSeason->id);

        $response = $this->get(route('players.show', $this->attacker->guid));

        $teamkillCount = $response->viewData('teamkillCount');
        // Should be 0 because no teamkills in the active season
        $this->assertSame(0, $teamkillCount);
    }

    public function test_map_stats_excludes_same_map_from_old_season(): void
    {
        $oldSeason = Season::current();
        // Create a kill on Toujane in old season
        $this->realMatchWithKill($oldSeason->id, 'mp_toujane_fix');

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        // Create a kill on the same map in new season
        $this->realMatchWithKill($newSeason->id, 'mp_toujane_fix');

        $response = $this->get(route('players.show', $this->attacker->guid));

        $mapStats = $response->viewData('player')->mapStats;
        // Should have exactly 1 map (Toujane), with exactly 1 kill (from active season only)
        $this->assertSame(1, $mapStats->count());
        $toujaneStats = $mapStats->first();
        $this->assertSame(1, $toujaneStats->kills);
    }
}
