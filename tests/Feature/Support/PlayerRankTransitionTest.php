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
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Transición gradual de rank_score en el arranque de temporada (2026-09-03,
 * a pedido del dueño, diseño completo en
 * docs/superpowers/specs/2026-09-03-transicion-rank-score-t2-design.md) --
 * SOLO interno a Equipos/TeamBalancer, /especialidades/rango no cambia.
 */
class PlayerRankTransitionTest extends TestCase
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
     * $matches partidas completas (9, para calificar) con $killsPerMatch
     * bajas reales por partida -- kd = $killsPerMatch exacto (1 muerte por
     * partida). Roster ganador de 2 (winner_guids repetido en 13 rondas por
     * partida), win rate 100% para todos -- mismo patrón que
     * PlayerRankFormulaTest::makePlayerWithKd(), generalizado con
     * $matches configurable para poder construir jugadores en transición
     * (M<9) además de calificados (M=9).
     */
    private function makePlayer(int $seasonId, int $matches, int $killsPerMatch, Player $teammate): Player
    {
        $player = Player::create(['guid' => random_int(100000, 999999), 'last_name' => 'P', 'last_name_plain' => 'P']);

        for ($m = 0; $m < $matches; $m++) {
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

            for ($k = 0; $k < $killsPerMatch; $k++) {
                Kill::create([
                    'round_id' => $rounds[$k]->id, 'match_id' => $match->id,
                    'attacker_player_id' => $player->id, 'attacker_guid' => $player->guid, 'attacker_name' => $player->last_name, 'attacker_team' => 'allies',
                    'victim_player_id' => $filler->id, 'victim_guid' => $filler->guid, 'victim_name' => $filler->last_name, 'victim_team' => 'axis',
                    'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                    'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
                ]);
            }
            Kill::create([
                'round_id' => $rounds[$killsPerMatch]->id, 'match_id' => $match->id,
                'attacker_player_id' => $filler->id, 'attacker_guid' => $filler->guid, 'attacker_name' => $filler->last_name, 'attacker_team' => 'axis',
                'victim_player_id' => $player->id, 'victim_guid' => $player->guid, 'victim_name' => $player->last_name, 'victim_team' => 'allies',
                'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
            ]);
        }

        return $player;
    }

    private function interpolatePercentile($poolValues, float $value): float
    {
        $method = new ReflectionMethod(PlayerRankCalculator::class, 'interpolatePercentile');
        $method->setAccessible(true);

        return $method->invoke(null, $poolValues, $value);
    }

    // -- interpolatePercentile(), unidad pura, sin DB -------------------

    public function test_interpolate_percentile_below_pool_range_is_zero(): void
    {
        $this->assertSame(0.0, $this->interpolatePercentile(collect([10, 20, 30]), 5.0));
    }

    public function test_interpolate_percentile_above_pool_range_is_one_hundred(): void
    {
        $this->assertSame(100.0, $this->interpolatePercentile(collect([10, 20, 30]), 35.0));
    }

    public function test_interpolate_percentile_exact_match_at_bottom_of_pool_is_zero(): void
    {
        $this->assertSame(0.0, $this->interpolatePercentile(collect([10, 20, 30]), 10.0));
    }

    public function test_interpolate_percentile_exact_match_in_middle_of_pool(): void
    {
        // sorted=[10,20,30], n=3 -- 20 es el indice 1, percentil = 1/(3-1)*100 = 50.
        $this->assertSame(50.0, $this->interpolatePercentile(collect([10, 20, 30]), 20.0));
    }

    public function test_interpolate_percentile_between_two_pool_points(): void
    {
        // 15 esta a mitad de camino entre 10 (indice 0) y 20 (indice 1):
        // posicion = 0.5, percentil = 0.5/2*100 = 25.
        $this->assertSame(25.0, $this->interpolatePercentile(collect([10, 20, 30]), 15.0));
    }

    public function test_interpolate_percentile_flat_pool_ties_at_the_neutral_midpoint(): void
    {
        // Pool completamente parejo (los 3 con el mismo valor) -- un valor
        // igual queda al medio (50), uno mejor arriba (100), uno peor abajo
        // (0). Sin esto, un valor empatado con un pool 100% plano caeria en
        // la rama "<= primero" y daria 0 incorrectamente (encontrado
        // armando este test).
        $this->assertSame(50.0, $this->interpolatePercentile(collect([50, 50, 50]), 50.0));
        $this->assertSame(100.0, $this->interpolatePercentile(collect([50, 50, 50]), 60.0));
        $this->assertSame(0.0, $this->interpolatePercentile(collect([50, 50, 50]), 40.0));
    }

    // -- transitionScoresForServer() -------------------------------------

    public function test_zero_matches_returns_the_neutral_seed_for_a_brand_new_player(): void
    {
        $player = Player::create(['guid' => 999001, 'last_name' => 'Nuevo', 'last_name_plain' => 'Nuevo']);

        $result = PlayerRankCalculator::transitionScoresForServer($this->server, [$player->guid]);

        $this->assertSame(50.0, $result[$player->guid]);
    }

    public function test_zero_matches_returns_the_exact_previous_season_seed(): void
    {
        $oldSeason = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);
        $best = $this->makePlayer($oldSeason->id, 9, 5, $teammate);
        // Segundo calificado en T1, necesario para que calculateForServer()
        // pueda calcular percentiles (n>=2, ver el comentario de esa
        // funcion sobre el caso n<=1).
        $this->makePlayer($oldSeason->id, 9, 1, $teammate);

        $oldSeason->update(['ended_at' => now()]);
        Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        // $best no jugó nada en T2 todavía (M=0) -- debe devolver exactamente
        // su semilla (percentil KD de T1), sin intentar interpolar nada.
        $result = PlayerRankCalculator::transitionScoresForServer($this->server, [$best->guid]);

        $this->assertSame(PlayerRankCalculator::seasonSeedScore($this->server, $best->guid), $result[$best->guid]);
    }

    public function test_insufficient_pool_falls_back_to_the_seed_regardless_of_matches_played(): void
    {
        $season = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);

        // Solo 3 calificados esta temporada -- por debajo de MIN_POOL_SIZE=10.
        $this->makePlayer($season->id, 9, 5, $teammate);
        $this->makePlayer($season->id, 9, 3, $teammate);
        $this->makePlayer($season->id, 9, 1, $teammate);

        // Jugador en transición con M=8 (a una partida de calificar) y un
        // desempeño excelente -- aun así, sin pool suficiente, debe quedar
        // en la semilla plana, nunca en un percentil ruidoso.
        $transitioning = $this->makePlayer($season->id, 8, 10, $teammate);

        $result = PlayerRankCalculator::transitionScoresForServer($this->server, [$transitioning->guid]);

        $this->assertSame(50.0, $result[$transitioning->guid]);
    }

    public function test_sufficient_pool_blends_seed_and_actual_score_proportionally_to_matches_played(): void
    {
        $season = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);

        // Pool de 10 calificados con K/D 1..10 (y por lo tanto Impacto
        // 9,18,...,90 -- cada kill es primera sangre de su propia ronda,
        // ver makePlayer()) -- una distribución real y conocida para
        // interpolar contra ella.
        for ($kd = 1; $kd <= 10; $kd++) {
            $this->makePlayer($season->id, 9, $kd, $teammate);
        }

        // Jugador nuevo (semilla=50) con M=6 partidas y 6 bajas/partida --
        // kd=6 (empata exacto con el calificado de kd=6 del pool) e
        // impacto crudo = 6*6=36 (empata exacto con el calificado de kd=4,
        // impacto crudo 4*9=36).
        $transitioning = $this->makePlayer($season->id, 6, 6, $teammate);

        $result = PlayerRankCalculator::transitionScoresForServer($this->server, [$transitioning->guid]);

        // P_KD: sorted=[1..10], valor=6 -> indice 4->5 (empate en el limite
        // superior del intervalo [5,6]), posicion=5, percentil=5/9*100=55.56.
        // P_IMP: sorted=[9,18,...,90], valor=36 -> intervalo [27,36],
        // posicion=3, percentil=3/9*100=33.33.
        // P_WR: pool 100% parejo, jugador también 100% -> empate neutro=50.
        $actual = 50.0 * 0.5 + 55.56 * 0.3 + 33.33 * 0.2;
        $expected = round((1 - 6 / 9) * 50.0 + (6 / 9) * $actual, 1);

        $this->assertSame($expected, $result[$transitioning->guid]);
    }

    public function test_computing_several_guids_at_once_does_not_scale_the_query_count_per_guid(): void
    {
        $season = Season::current();
        $teammate = Player::create(['guid' => 1, 'last_name' => 'T', 'last_name_plain' => 'T']);

        for ($kd = 1; $kd <= 10; $kd++) {
            $this->makePlayer($season->id, 9, $kd, $teammate);
        }

        $p1 = $this->makePlayer($season->id, 3, 2, $teammate);
        $p2 = $this->makePlayer($season->id, 4, 3, $teammate);
        $p3 = $this->makePlayer($season->id, 5, 4, $teammate);

        // Se limpia la memoizacion de seasonSeedScore() antes de cada
        // medicion -- sin esto, la primera llamada "calienta" el cache
        // estatico (2026-09-02) y la segunda queda artificialmente mas
        // barata solo por el orden, no por la cantidad de guids pedidos
        // (encontrado escribiendo este test: comparar 1 vs 3 guids sin
        // resetear el cache entre medidas mezclaba las dos variables).
        PlayerRankCalculator::clearSeasonSeedCache();
        DB::enableQueryLog();
        PlayerRankCalculator::transitionScoresForServer($this->server, [$p1->guid]);
        $queriesForOne = count(DB::getQueryLog());

        PlayerRankCalculator::clearSeasonSeedCache();
        DB::flushQueryLog();
        PlayerRankCalculator::transitionScoresForServer($this->server, [$p1->guid, $p2->guid, $p3->guid]);
        $queriesForThree = count(DB::getQueryLog());

        // El pool de calificados y las stats crudas de la temporada se
        // calculan UNA sola vez sin importar cuantos guids se pidan --
        // mismo motivo que evitó el bug de performance de 9f56224 con
        // seasonSeedScore(). Si esto alguna vez vuelve a escalar por guid,
        // este test debe fallar.
        $this->assertSame($queriesForOne, $queriesForThree);
    }
}
