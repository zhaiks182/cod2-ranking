<?php

namespace Tests\Feature\Support;

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
 * Formula nueva de rango (2026-09-02, a pedido del dueño): 50% win rate +
 * 30% K/D + 20% Impacto, con insignias S/A/B/C/D por distribucion normal
 * seccionada (5/20/50/20/5% de la POSICION en la tabla, no del valor del
 * score). Ver PlayerRankCalculator y docs/superpowers/specs si existiera.
 */
class PlayerRankFormulaTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    /**
     * $kills kills reales por partida (una por ronda distinta, nunca mas de
     * una por ronda -- evita contaminar Impacto con bonos de multi-kill) mas
     * exactamente 1 muerte real (en otra ronda aparte) -- kd = $kills
     * exacto. Roster ganador de 2 (por debajo del piso de 3 para clutch),
     * winner_guids repetido en 13 rondas reales por partida (TeamSideAnalyzer
     * exige 2+ rondas con winner_guids para poder resolver un ganador) --
     * win rate 100% identico para todos, la unica variable real entre
     * jugadores es el K/D.
     *
     * Cada partida usa un rival de relleno NUEVO y descartable (nunca el
     * mismo entre partidas) -- si se reusara un solo rival contra varios
     * jugadores de prueba, el rival mismo terminaria acumulando suficientes
     * partidas jugadas como para calificar el (con un K/D pesimo) y
     * contaminar el pool de percentiles que este test intenta mantener
     * limpio (bug real encontrado armando este test: con un rival
     * compartido, el rival colaba como jugador N+1).
     */
    private function makePlayerWithKd(int $seasonId, int $kills, Player $teammate): Player
    {
        $player = Player::create(['guid' => random_int(100000, 999999), 'last_name' => "P{$kills}", 'last_name_plain' => "P{$kills}"]);

        for ($m = 0; $m < 9; $m++) {
            $filler = Player::create(['guid' => random_int(1000000, 9999999), 'last_name' => 'F', 'last_name_plain' => 'F']);
            $match = GameMatch::create([
                'server_id' => $this->server->id, 'season_id' => $seasonId,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);

            $rounds = [];
            for ($i = 1; $i <= 13; $i++) {
                $rounds[] = Round::create([
                    'server_id' => $this->server->id, 'match_id' => $match->id,
                    'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
                    'winner_guids' => [$player->guid, $teammate->guid],
                ]);
            }

            // Una kill real del jugador por ronda distinta (nunca 2+ en la
            // misma ronda) para llegar a $kills sin disparar multi-kill.
            for ($k = 0; $k < $kills; $k++) {
                Kill::create([
                    'round_id' => $rounds[$k]->id, 'match_id' => $match->id,
                    'attacker_player_id' => $player->id, 'attacker_guid' => $player->guid, 'attacker_name' => $player->last_name, 'attacker_team' => 'allies',
                    'victim_player_id' => $filler->id, 'victim_guid' => $filler->guid, 'victim_name' => $filler->last_name, 'victim_team' => 'axis',
                    'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                    'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
                ]);
            }
            // Exactamente 1 muerte real, en una ronda aparte de las de arriba.
            Kill::create([
                'round_id' => $rounds[$kills]->id, 'match_id' => $match->id,
                'attacker_player_id' => $filler->id, 'attacker_guid' => $filler->guid, 'attacker_name' => $filler->last_name, 'attacker_team' => 'axis',
                'victim_player_id' => $player->id, 'victim_guid' => $player->guid, 'victim_name' => $player->last_name, 'victim_team' => 'allies',
                'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
            ]);
        }

        return $player;
    }

    public function test_tiers_follow_the_5_20_50_20_5_percent_split_of_table_position(): void
    {
        $season = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);

        // 5 jugadores con K/D distinto y estrictamente decreciente: 5,4,3,2,1.
        $p1 = $this->makePlayerWithKd($season->id, 5, $teammate);
        $p2 = $this->makePlayerWithKd($season->id, 4, $teammate);
        $p3 = $this->makePlayerWithKd($season->id, 3, $teammate);
        $p4 = $this->makePlayerWithKd($season->id, 2, $teammate);
        $p5 = $this->makePlayerWithKd($season->id, 1, $teammate);

        $ranks = PlayerRankCalculator::calculateForServer($this->server);

        // N=5: P_tabla = 100, 75, 50, 25, 0 -- exactamente los cortes de
        // TIER_CUTOFFS (95/75/25/5), confirmando el limite de cada banda.
        $this->assertSame('S', $ranks[$p1->guid]->rango); // posicion 1, P=100
        $this->assertSame('A', $ranks[$p2->guid]->rango); // posicion 2, P=75 (limite de A)
        $this->assertSame('B', $ranks[$p3->guid]->rango); // posicion 3, P=50
        $this->assertSame('B', $ranks[$p4->guid]->rango); // posicion 4, P=25 (limite de B, no C)
        $this->assertSame('D', $ranks[$p5->guid]->rango); // posicion 5, P=0
    }

    public function test_the_score_uses_the_new_weights_not_the_old_ones(): void
    {
        $season = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);

        $p1 = $this->makePlayerWithKd($season->id, 5, $teammate);
        $p2 = $this->makePlayerWithKd($season->id, 1, $teammate);

        $ranks = PlayerRankCalculator::calculateForServer($this->server);

        // Win rate identico (100%, tal cual esperado -- diff=0) para los dos.
        // K/D e Impacto SI difieren entre p1 y p2 aca: makePlayerWithKd()
        // reparte cada kill real en su propia ronda para no disparar
        // multi-kill, pero eso significa que cada una de esas rondas es
        // "primera sangre" de esa ronda -- con solo 2 jugadores, tanto kdPct
        // como impactPct terminan siendo 100 (p1) y 0 (p2). Score esperado:
        // p1 = 0*0.5 + 100*0.3 + 100*0.2 = 50; p2 = 0. La formula vieja
        // (70% K/D + 30% win) hubiera dado una diferencia de 70, no 50 --
        // lo que importa de este test es que la formula realmente cambio.
        $this->assertSame(100.0, $ranks[$p1->guid]->kdPct);
        $this->assertSame(0.0, $ranks[$p2->guid]->kdPct);
        $this->assertSame(50.0, round($ranks[$p1->guid]->score - $ranks[$p2->guid]->score, 1));
    }

    public function test_season_seed_score_is_null_without_a_previous_closed_season(): void
    {
        $this->assertNull(PlayerRankCalculator::seasonSeedScore($this->server, 12345));
    }

    public function test_season_seed_score_uses_kd_percentile_from_the_previous_closed_season(): void
    {
        $oldSeason = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);
        $best = $this->makePlayerWithKd($oldSeason->id, 5, $teammate);
        $worst = $this->makePlayerWithKd($oldSeason->id, 1, $teammate);

        $oldSeason->update(['ended_at' => now()]);
        Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        // $best no jugo nada en la temporada nueva (sigue con MIN_MATCHES=0 ahi),
        // pero calificaba de sobra en la temporada anterior -- la semilla debe
        // resolver a su percentil de K/D de esa temporada (100, el mas alto de 2).
        $this->assertSame(100.0, PlayerRankCalculator::seasonSeedScore($this->server, $best->guid));
        $this->assertSame(0.0, PlayerRankCalculator::seasonSeedScore($this->server, $worst->guid));
    }

    public function test_season_seed_score_is_null_for_a_player_who_never_qualified(): void
    {
        $oldSeason = Season::current();
        $oldSeason->update(['ended_at' => now()]);
        Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $this->assertNull(PlayerRankCalculator::seasonSeedScore($this->server, 555555));
    }
}
