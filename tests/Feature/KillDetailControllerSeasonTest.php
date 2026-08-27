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

/**
 * Finding 2 del final review: el popover de kills (data-kills-trigger, servido por
 * KillDetailController::index()) ignoraba por completo el parametro 'season' de la
 * URL -- el numero en pantalla ya esta scopeado a una temporada (leaderboard.blade.php
 * y players/show.blade.php ya mandan season=... en $tkParams), pero el popover
 * siempre calculaba sobre el historial completo. Estos tests cubren el fix.
 */
class KillDetailControllerSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

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
    }

    /** Partida real que llego a 13 rondas (cuenta para GameMatch::forSeason()) — 1 kill de $attacker contra $victim. */
    private function realMatch(int $seasonId, Player $attacker, Player $victim): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => 'mp_toujane_fix',
                'gametype' => 'sd',
                'started_at' => now(),
                'ended_at' => now(),
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
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
            'weapon' => 'weapon_mp44',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'hitloc' => 'head',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_kill_detail_with_explicit_active_season_excludes_old_season_kill(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $oldVictim = Player::create(['guid' => 222, 'last_name' => 'OldVictim', 'last_name_plain' => 'OldVictim']);
        $newVictim = Player::create(['guid' => 333, 'last_name' => 'NewVictim', 'last_name_plain' => 'NewVictim']);

        $this->realMatch($oldSeason->id, $attacker, $oldVictim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $newVictim);

        $response = $this->getJson(route('kills.detail', [$attacker->guid, 'season' => $newSeason->id]));

        $response->assertOk();
        $victims = collect($response->json())->pluck('victim');
        $this->assertTrue($victims->contains('NewVictim'));
        $this->assertFalse($victims->contains('OldVictim'));
    }

    public function test_kill_detail_with_season_all_includes_every_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $oldVictim = Player::create(['guid' => 222, 'last_name' => 'OldVictim', 'last_name_plain' => 'OldVictim']);
        $newVictim = Player::create(['guid' => 333, 'last_name' => 'NewVictim', 'last_name_plain' => 'NewVictim']);

        $this->realMatch($oldSeason->id, $attacker, $oldVictim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $newVictim);

        $response = $this->getJson(route('kills.detail', [$attacker->guid, 'season' => 'all']));

        $response->assertOk();
        $victims = collect($response->json())->pluck('victim');
        $this->assertTrue($victims->contains('NewVictim'));
        $this->assertTrue($victims->contains('OldVictim'));
    }

    public function test_kill_detail_without_season_param_behaves_like_all(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $oldVictim = Player::create(['guid' => 222, 'last_name' => 'OldVictim', 'last_name_plain' => 'OldVictim']);
        $newVictim = Player::create(['guid' => 333, 'last_name' => 'NewVictim', 'last_name_plain' => 'NewVictim']);

        $this->realMatch($oldSeason->id, $attacker, $oldVictim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $newVictim);

        $response = $this->getJson(route('kills.detail', [$attacker->guid]));

        $response->assertOk();
        $victims = collect($response->json())->pluck('victim');
        $this->assertTrue($victims->contains('NewVictim'));
        $this->assertTrue($victims->contains('OldVictim'));
    }
}
