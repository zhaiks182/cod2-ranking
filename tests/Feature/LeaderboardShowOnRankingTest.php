<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preferencia real "Mostrar mi perfil en el ranking" (2026-09-01, /mi-cuenta,
 * modulo de perfil gaming) -- a diferencia del badge de rol (cosmetico), esta
 * si filtra de verdad. Ver tambien DashboardShowOnRankingTest para el mismo
 * comportamiento en el top de la home.
 */
class LeaderboardShowOnRankingTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function realMatch(Player $attacker, Player $victim): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => Season::current()->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
                'started_at' => now(), 'ended_at' => now(),
            ]);
        }

        Kill::create([
            'round_id' => $match->rounds()->first()->id, 'match_id' => $match->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
            'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'head',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_a_player_with_show_on_ranking_false_is_excluded_from_the_table(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($attacker, $victim);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $attacker->id, 'show_on_ranking' => false]);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNull($row);
    }

    public function test_a_claimed_player_without_opting_out_still_appears(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($attacker, $victim);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $attacker->id]);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
    }
}
