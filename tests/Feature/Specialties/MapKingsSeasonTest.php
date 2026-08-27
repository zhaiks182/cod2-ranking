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

class MapKingsSeasonTest extends TestCase
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
     * Partida real que llega a 13 rondas (cuenta como concluida, no queda excluida
     * por scopeAbandonedWithoutConclusion()) en mp_toujane_fix, con $killCount kills
     * de $attacker contra $victim y $deathCount kills de $victim contra $attacker
     * (para poder verificar topDeaths).
     */
    private function realMatchOnMap(int $seasonId, Player $attacker, Player $victim, int $killCount, int $deathCount = 0): GameMatch
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

        $makeKill = function (Player $killer, Player $died) use ($round, $match) {
            Kill::create([
                'round_id' => $round->id,
                'match_id' => $match->id,
                'attacker_player_id' => $killer->id,
                'attacker_guid' => $killer->guid,
                'attacker_name' => $killer->last_name,
                'attacker_team' => 'allies',
                'victim_player_id' => $died->id,
                'victim_guid' => $died->guid,
                'victim_name' => $died->last_name,
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
        };

        for ($i = 0; $i < $killCount; $i++) {
            $makeKill($attacker, $victim);
        }
        for ($i = 0; $i < $deathCount; $i++) {
            $makeKill($victim, $attacker);
        }

        return $match;
    }

    /**
     * 2 temporadas, cada una con una partida en el MISMO mapa (mp_toujane_fix) pero
     * distinto "rey" y distinto total de kills:
     * - Temporada vieja: killerA con 5 kills (2 muertes).
     * - Temporada activa: killerB con 10 kills (3 muertes) -- mas que killerA, asi
     *   que el "rey" combinado (?season=all) sigue siendo killerB sin ambiguedad
     *   (10 > 5), y el total combinado (15) es mayor al de cualquier temporada sola.
     */
    public function test_default_view_shows_only_active_season_map_king_and_total(): void
    {
        $oldSeason = Season::current();
        $killerA = Player::create(['guid' => 111, 'last_name' => 'KillerA', 'last_name_plain' => 'KillerA']);
        $killerB = Player::create(['guid' => 222, 'last_name' => 'KillerB', 'last_name_plain' => 'KillerB']);
        $victim = Player::create(['guid' => 333, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);

        $this->realMatchOnMap($oldSeason->id, $killerA, $victim, killCount: 5, deathCount: 2);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchOnMap($newSeason->id, $killerB, $victim, killCount: 10, deathCount: 3);

        $response = $this->get(route('specialties.map-kings', ['server' => $this->server->slug]));
        $response->assertOk();

        $row = collect($response->viewData('maps'))->firstWhere('map', 'mp_toujane_fix');
        $this->assertNotNull($row);
        $this->assertSame($killerB->id, $row->topPlayer->id); // solo la temporada activa
        $this->assertSame(10, $row->topKills);
        $this->assertSame(3, $row->topDeaths);
        // uses = TODOS los kills del mapa en la temporada (ambas direcciones -- las
        // muertes de killerB tambien son kills de victim, y cuentan igual): 10 + 3.
        $this->assertSame(13, $row->uses);
    }

    public function test_season_all_combines_both_seasons_kills_with_unambiguous_top_killer(): void
    {
        $oldSeason = Season::current();
        $killerA = Player::create(['guid' => 111, 'last_name' => 'KillerA', 'last_name_plain' => 'KillerA']);
        $killerB = Player::create(['guid' => 222, 'last_name' => 'KillerB', 'last_name_plain' => 'KillerB']);
        $victim = Player::create(['guid' => 333, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);

        $this->realMatchOnMap($oldSeason->id, $killerA, $victim, killCount: 5, deathCount: 2);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchOnMap($newSeason->id, $killerB, $victim, killCount: 10, deathCount: 3);

        $responseAll = $this->get(route('specialties.map-kings', ['server' => $this->server->slug, 'season' => 'all']));
        $responseAll->assertOk();

        $rowAll = collect($responseAll->viewData('maps'))->firstWhere('map', 'mp_toujane_fix');
        $this->assertNotNull($rowAll);
        $this->assertSame($killerB->id, $rowAll->topPlayer->id); // 10 > 5, sin ambiguedad
        $this->assertSame(10, $rowAll->topKills);
        $this->assertSame(3, $rowAll->topDeaths);
        // uses combina las 2 temporadas, ambas direcciones: (5+2) + (10+3) = 20.
        $this->assertSame(20, $rowAll->uses);
    }
}
