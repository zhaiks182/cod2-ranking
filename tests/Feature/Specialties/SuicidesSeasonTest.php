<?php

namespace Tests\Feature\Specialties;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuicidesSeasonTest extends TestCase
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

    /**
     * Partida real que llega a 13 rondas (cuenta como concluida) con 1 suicidio
     * del $attacker.
     */
    private function realMatchWithSuicide(int $seasonId, Player $attacker): GameMatch
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
            'victim_player_id' => $attacker->id,
            'victim_guid' => $attacker->guid,
            'victim_name' => $attacker->last_name,
            'victim_team' => 'allies',
            'weapon' => 'grenade_frag',
            'damage' => 100,
            'mod' => 'MOD_GRENADE',
            'hitloc' => 'torso_upper',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => true,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_suicides_excludes_old_season_suicides(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $this->realMatchWithSuicide($oldSeason->id, $attacker);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithSuicide($newSeason->id, $attacker);
        $this->realMatchWithSuicide($newSeason->id, $attacker);

        $response = $this->get(route('specialties.suicides', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value); // solo la temporada activa

        $responseAll = $this->get(route('specialties.suicides', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(3, $rowAll->value); // las 2 temporadas
    }
}
