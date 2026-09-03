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

class GroupASeasonTest extends TestCase
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
     * Partida real que llega a 13 rondas (cuenta como concluida) con 1 kill de
     * $attacker contra $victim, con los flags que pida cada caso.
     */
    private function realMatchWithKill(
        int $seasonId,
        Player $attacker,
        Player $victim,
        bool $isGrenade = false,
        bool $isHeadshot = false,
        bool $isTeamkill = false,
        ?string $mod = null,
    ): GameMatch {
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
            'victim_team' => $isTeamkill ? 'allies' : 'axis',
            'weapon' => $isGrenade ? 'grenade_frag' : 'weapon_mp44',
            'damage' => 100,
            'mod' => $mod ?? ($isGrenade ? 'MOD_GRENADE' : 'MOD_RIFLE_BULLET'),
            'hitloc' => $isHeadshot ? 'head' : 'torso_upper',
            'is_headshot' => $isHeadshot,
            'is_grenade' => $isGrenade,
            'is_suicide' => false,
            'is_teamkill' => $isTeamkill,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_grenades_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKill($oldSeason->id, $attacker, $victim, isGrenade: true);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, isGrenade: true);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, isGrenade: true);

        $response = $this->get(route('specialties.grenades', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value); // solo la temporada activa

        $responseAll = $this->get(route('specialties.grenades', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(3, $rowAll->value); // las 2 temporadas
    }

    public function test_headshots_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKill($oldSeason->id, $attacker, $victim, isHeadshot: true);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, isHeadshot: true);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, isHeadshot: true);

        $response = $this->get(route('specialties.headshots', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value);

        $responseAll = $this->get(route('specialties.headshots', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(3, $rowAll->value);
    }

    public function test_friendly_fire_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKill($oldSeason->id, $attacker, $victim, isTeamkill: true);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, isTeamkill: true);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, isTeamkill: true);

        $response = $this->get(route('specialties.friendly-fire', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value);

        $responseAll = $this->get(route('specialties.friendly-fire', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(3, $rowAll->value);
    }

    public function test_bash_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKill($oldSeason->id, $attacker, $victim, mod: 'MOD_MELEE');

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, mod: 'MOD_MELEE');
        $this->realMatchWithKill($newSeason->id, $attacker, $victim, mod: 'MOD_MELEE');

        $response = $this->get(route('specialties.bash', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value);

        $responseAll = $this->get(route('specialties.bash', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(3, $rowAll->value);
    }

    /**
     * efficiency() exige >= 20 kills totales ($minKills) para aparecer en el
     * ranking -- se generan 20 kills en la temporada vieja (10 muertes propias,
     * K/D=2.0) y otras 20 en la nueva (5 muertes, K/D=4.0) para poder distinguir
     * cual temporada esta activa por el valor de K/D mostrado.
     */
    public function test_efficiency_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->createKillsAndDeaths($oldSeason->id, $attacker, $victim, kills: 20, deaths: 10);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        // /eficiencia ahora exige tambien un minimo de PARTIDAS (2026-09-02,
        // ver PlayerRankCalculator::MIN_MATCHES), no solo de bajas -- se
        // reparten las mismas 20 bajas/5 muertes en 9 partidas distintas en
        // vez de una sola, mismo K/D final (20/5=4.0).
        for ($i = 0; $i < 8; $i++) {
            $this->createKillsAndDeaths($newSeason->id, $attacker, $victim, kills: 2, deaths: 0);
        }
        $this->createKillsAndDeaths($newSeason->id, $attacker, $victim, kills: 4, deaths: 5);

        $response = $this->get(route('specialties.efficiency', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(4.0, $row->value); // solo temporada activa: 20/5

        $responseAll = $this->get(route('specialties.efficiency', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2.67, $rowAll->value); // las 2 temporadas: 40/15 = 2.666... -> round 2.67
    }

    /** Crea una partida concluida (13 rondas) con $kills kills de $attacker y $deaths muertes suyas. */
    private function createKillsAndDeaths(int $seasonId, Player $attacker, Player $victim, int $kills, int $deaths): void
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

        for ($i = 0; $i < $kills; $i++) {
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
                'hitloc' => 'torso_upper',
                'is_headshot' => false,
                'is_grenade' => false,
                'is_suicide' => false,
                'is_teamkill' => false,
                'occurred_at' => now(),
            ]);
        }

        for ($i = 0; $i < $deaths; $i++) {
            Kill::create([
                'round_id' => $round->id,
                'match_id' => $match->id,
                'attacker_player_id' => $victim->id,
                'attacker_guid' => $victim->guid,
                'attacker_name' => $victim->last_name,
                'attacker_team' => 'axis',
                'victim_player_id' => $attacker->id,
                'victim_guid' => $attacker->guid,
                'victim_name' => $attacker->last_name,
                'victim_team' => 'allies',
                'weapon' => 'weapon_mp44',
                'damage' => 100,
                'mod' => 'MOD_RIFLE_BULLET',
                'hitloc' => 'torso_upper',
                'is_headshot' => false,
                'is_grenade' => false,
                'is_suicide' => false,
                'is_teamkill' => false,
                'occurred_at' => now(),
            ]);
        }
    }
}
