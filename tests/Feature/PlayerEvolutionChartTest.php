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
 * Grafico de evolucion en el perfil de jugador (2026-08-31, a pedido del
 * dueño) -- kills/muertes/K-D de las ultimas 15 partidas DECIDIDAS (mismo
 * criterio que WinRateCalculator::matchHistoryForPlayer(), que ya excluye
 * partidas sin ganador determinable), orden cronologico.
 */
class PlayerEvolutionChartTest extends TestCase
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

    /** Partida DECIDIDA (13 rondas con winner_guids) con $kills bajas y $deaths muertes de $player contra $rival. */
    private function decidedMatch(Player $player, Player $rival, int $kills, int $deaths, \DateTimeInterface $startedAt): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => Season::current()->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => $startedAt,
            'ended_at' => $startedAt,
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => 'mp_toujane_fix',
                'gametype' => 'sd',
                'started_at' => $startedAt,
                'ended_at' => $startedAt,
                'winner_guids' => [$player->guid],
            ]);
        }

        $round = $match->rounds()->first();
        $makeKill = function (Player $attacker, Player $victim) use ($round, $match) {
            Kill::create([
                'round_id' => $round->id, 'match_id' => $match->id,
                'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
                'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
                'weapon' => 'weapon_mp44', 'mod' => 'MOD_RIFLE_BULLET', 'damage' => 100,
                'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false,
                'occurred_at' => $round->started_at,
            ]);
        };

        for ($i = 0; $i < $kills; $i++) {
            $makeKill($player, $rival);
        }
        for ($i = 0; $i < $deaths; $i++) {
            $makeKill($rival, $player);
        }

        return $match;
    }

    public function test_evolution_chart_shows_kills_and_deaths_per_match_in_chronological_order(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Player', 'last_name_plain' => 'Player']);
        $rival = Player::create(['guid' => 222, 'last_name' => 'Rival', 'last_name_plain' => 'Rival']);

        $this->decidedMatch($player, $rival, kills: 20, deaths: 10, startedAt: now()->subDays(2));
        $this->decidedMatch($player, $rival, kills: 5, deaths: 15, startedAt: now()->subDay());

        $response = $this->get(route('players.show', $player->guid));

        $response->assertOk();
        $response->assertViewHas('evolutionChart', function ($chart) {
            return $chart->count() === 2
                && $chart[0]['kills'] === 20 && $chart[0]['deaths'] === 10
                && $chart[1]['kills'] === 5 && $chart[1]['deaths'] === 15;
        });
        $response->assertSee('cod2-evolution-chart', false);
        $response->assertSee('chart.js', false);
    }

    public function test_no_chart_markup_when_the_player_has_no_decided_matches(): void
    {
        $player = Player::create(['guid' => 333, 'last_name' => 'Lonely', 'last_name_plain' => 'Lonely']);

        $response = $this->get(route('players.show', $player->guid));

        $response->assertOk();
        $response->assertViewHas('evolutionChart', fn ($chart) => $chart->isEmpty());
        $response->assertDontSee('cod2-evolution-chart', false);
    }

    public function test_only_the_last_15_matches_are_included(): void
    {
        $player = Player::create(['guid' => 444, 'last_name' => 'Grinder', 'last_name_plain' => 'Grinder']);
        $rival = Player::create(['guid' => 555, 'last_name' => 'Rival', 'last_name_plain' => 'Rival']);

        for ($i = 20; $i >= 1; $i--) {
            $this->decidedMatch($player, $rival, kills: $i, deaths: 1, startedAt: now()->subDays($i));
        }

        $response = $this->get(route('players.show', $player->guid));

        $response->assertOk();
        $response->assertViewHas('evolutionChart', function ($chart) {
            // Las ultimas 15 (mas recientes), en orden cronologico -- la mas
            // vieja de esas 15 tiene kills=15 (subDays(15)), la mas nueva kills=1.
            return $chart->count() === 15 && $chart->first()['kills'] === 15 && $chart->last()['kills'] === 1;
        });
    }
}
