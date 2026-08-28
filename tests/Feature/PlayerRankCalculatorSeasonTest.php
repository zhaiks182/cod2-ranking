<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Support\PlayerRankCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlayerRankCalculator es el calculo compartido entre /especialidades/rango
 * (SpecialtyController::rango(), que ahora delega en este mismo metodo en vez
 * de duplicar la logica) y el balanceador de Equipos (/equipos,
 * TeamBalanceController) -- unificado el 2026-08-27 para que los dos dejen de
 * poder desincronizarse. Mismo fixture (rangoMatch) que
 * tests/Feature/Specialties/GroupDSeasonTest.php::test_rango_excludes_old_season_kills,
 * llamando al calculator directo en vez de pasar por el controller/HTTP.
 */
class PlayerRankCalculatorSeasonTest extends TestCase
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
     * Partida completa (13 rondas, ganadas por el roster [$a, $r]) con $aKills/$aDeaths
     * kills/muertes de $a y $rKills/$rDeaths de $r, todos contra $v -- mismo helper que
     * GroupDSeasonTest::rangoMatch().
     */
    private function rangoMatch(int $seasonId, Player $a, Player $r, Player $v, int $aKills, int $aDeaths, int $rKills, int $rDeaths): GameMatch
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
                'winner_guids' => [$a->guid, $r->guid],
            ]);
        }

        $round = $match->rounds()->first();

        $makeKill = function (Player $attacker, Player $victim) use ($round, $match) {
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
        };

        for ($i = 0; $i < $aKills; $i++) {
            $makeKill($a, $v);
        }
        for ($i = 0; $i < $aDeaths; $i++) {
            $makeKill($v, $a);
        }
        for ($i = 0; $i < $rKills; $i++) {
            $makeKill($r, $v);
        }
        for ($i = 0; $i < $rDeaths; $i++) {
            $makeKill($v, $r);
        }

        return $match;
    }

    public function test_calculate_for_server_without_season_id_uses_the_active_season(): void
    {
        $oldSeason = Season::current();
        $a = Player::create(['guid' => 901, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $r = Player::create(['guid' => 902, 'last_name' => 'R', 'last_name_plain' => 'R']);
        $v = Player::create(['guid' => 903, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Temporada vieja: 10 partidas (MIN_MATCHES), a: 4 kills/4 muertes cada una (40/40, K/D=1.0).
        for ($i = 0; $i < 10; $i++) {
            $this->rangoMatch($oldSeason->id, $a, $r, $v, 4, 4, 4, 0);
        }

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        // Temporada nueva: 10 partidas, a: 4 kills/2 muertes cada una (40/20, K/D=2.0).
        for ($i = 0; $i < 10; $i++) {
            $this->rangoMatch($newSeason->id, $a, $r, $v, 4, 2, 4, 4);
        }

        $ranks = PlayerRankCalculator::calculateForServer($this->server);
        $this->assertSame(2.0, $ranks[$a->guid]->kd); // solo la temporada activa (nueva): 40/20
    }

    public function test_calculate_for_server_with_an_explicit_closed_season_id(): void
    {
        $oldSeason = Season::current();
        $a = Player::create(['guid' => 911, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $r = Player::create(['guid' => 912, 'last_name' => 'R', 'last_name_plain' => 'R']);
        $v = Player::create(['guid' => 913, 'last_name' => 'V', 'last_name_plain' => 'V']);

        for ($i = 0; $i < 10; $i++) {
            $this->rangoMatch($oldSeason->id, $a, $r, $v, 4, 4, 4, 0);
        }

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        for ($i = 0; $i < 10; $i++) {
            $this->rangoMatch($newSeason->id, $a, $r, $v, 4, 2, 4, 4);
        }

        // Sin segundo argumento: activa (Temporada 2) -- K/D 2.0.
        $activeRanks = PlayerRankCalculator::calculateForServer($this->server);
        $this->assertSame(2.0, $activeRanks[$a->guid]->kd);

        // Con el id explícito de la temporada cerrada: K/D 1.0, no la de la activa.
        $closedRanks = PlayerRankCalculator::calculateForServer($this->server, $oldSeason->id);
        $this->assertSame(1.0, $closedRanks[$a->guid]->kd);

        // Con 'all': combinado (80/60 = 1.33), igual que /rango?season=all.
        $allRanks = PlayerRankCalculator::calculateForServer($this->server, 'all');
        $this->assertSame(1.33, $allRanks[$a->guid]->kd);
    }

    public function test_rango_and_equipos_agree_on_the_same_scope(): void
    {
        $a = Player::create(['guid' => 921, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $r = Player::create(['guid' => 922, 'last_name' => 'R', 'last_name_plain' => 'R']);
        $v = Player::create(['guid' => 923, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $season = Season::current();
        for ($i = 0; $i < 10; $i++) {
            $this->rangoMatch($season->id, $a, $r, $v, 4, 2, 4, 4);
        }

        $response = $this->get(route('rango', ['server' => $this->server->slug]));
        $response->assertOk();
        $rangoRow = collect($response->viewData('rows'))->first(fn ($row) => $row->player->guid === $a->guid);

        $equiposRanks = PlayerRankCalculator::calculateForServer($this->server);

        // /rango y el calculator que usa Equipos deben coincidir exactamente en la
        // misma temporada -- el punto entero de haberlos unificado.
        $this->assertSame($equiposRanks[$a->guid]->kd, $rangoRow->kd);
        $this->assertSame($equiposRanks[$a->guid]->rango, $rangoRow->rango);
    }

    public function test_low_kill_count_no_longer_disqualifies_a_player_with_enough_matches(): void
    {
        $season = Season::current();
        $a = Player::create(['guid' => 931, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $r = Player::create(['guid' => 932, 'last_name' => 'R', 'last_name_plain' => 'R']);
        $v = Player::create(['guid' => 933, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // 10 partidas (cumple MIN_MATCHES), solo 1 kill por partida -- 10 kills
        // totales, muy por debajo del viejo minimo de 20 bajas, que ya no existe.
        for ($i = 0; $i < 10; $i++) {
            $this->rangoMatch($season->id, $a, $r, $v, 1, 1, 4, 4);
        }

        $ranks = PlayerRankCalculator::calculateForServer($this->server);
        $this->assertTrue($ranks->has($a->guid));
    }

    public function test_fewer_than_the_minimum_matches_still_does_not_qualify(): void
    {
        $season = Season::current();
        $a = Player::create(['guid' => 941, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $r = Player::create(['guid' => 942, 'last_name' => 'R', 'last_name_plain' => 'R']);
        $v = Player::create(['guid' => 943, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // 8 partidas -- uno menos que el nuevo MIN_MATCHES (9), a pesar de tener
        // muchas mas de 20 kills (viejo umbral eliminado, no alcanza igual).
        for ($i = 0; $i < 8; $i++) {
            $this->rangoMatch($season->id, $a, $r, $v, 10, 1, 4, 4);
        }

        $ranks = PlayerRankCalculator::calculateForServer($this->server);
        $this->assertFalse($ranks->has($a->guid));
    }
}
