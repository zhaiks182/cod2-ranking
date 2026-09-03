<?php

namespace Tests\Feature\Specialties;

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
 * Grupo "Combate" del dropdown ESPECIALISTA (2026-09-02, a pedido del dueño,
 * caso real: "nightwalker" aparecio 2do en /eficiencia con muy pocas
 * partidas jugadas) -- dos cambios:
 * 1. /eficiencia exige tambien un minimo de PARTIDAS (no solo de bajas).
 * 2. El mismo criterio de inactividad de /rango (15+ dias sin jugar Y
 *    horas por debajo del promedio de la temporada, las dos a la vez) se
 *    replica en las paginas de Combate -- probado aca con /headshots como
 *    representante del resto (todas comparten el mismo helper).
 */
class CombateInactivityAndMinMatchesTest extends TestCase
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
     * $matches partidas completas (13 rondas c/u), $killsPerMatch bajas
     * reales por partida contra un rival unico por partida. $roundMinutes
     * controla la duracion de la primera ronda de cada partida (via
     * PlaytimeCalculator, mismo patron que RangoInactivityTest) para poder
     * controlar las horas jugadas del jugador.
     */
    private function makePlayerWithMatches(int $seasonId, int $matches, int $killsPerMatch, bool $headshots = false, int $roundMinutes = 60): Player
    {
        $player = Player::create(['guid' => random_int(100000, 999999), 'last_name' => 'P', 'last_name_plain' => 'P']);

        for ($m = 0; $m < $matches; $m++) {
            $filler = Player::create(['guid' => random_int(1000000, 9999999), 'last_name' => 'F', 'last_name_plain' => 'F']);
            $match = GameMatch::create([
                'server_id' => $this->server->id, 'season_id' => $seasonId,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);
            for ($i = 1; $i <= 13; $i++) {
                Round::create([
                    'server_id' => $this->server->id, 'match_id' => $match->id,
                    'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(),
                    'ended_at' => $i === 1 ? now()->addMinutes($roundMinutes) : now(),
                    'winner_guids' => [$player->guid],
                ]);
            }
            for ($k = 0; $k < $killsPerMatch; $k++) {
                Kill::create([
                    'round_id' => $match->rounds()->first()->id, 'match_id' => $match->id,
                    'attacker_player_id' => $player->id, 'attacker_guid' => $player->guid, 'attacker_name' => $player->last_name, 'attacker_team' => 'allies',
                    'victim_player_id' => $filler->id, 'victim_guid' => $filler->guid, 'victim_name' => $filler->last_name, 'victim_team' => 'axis',
                    'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'head',
                    'is_headshot' => $headshots, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
                ]);
            }
        }

        return $player;
    }

    public function test_efficiency_excludes_a_player_with_enough_kills_but_not_enough_matches(): void
    {
        $season = Season::current();
        // 25 bajas (supera el minimo de 20) pero solo en 2 partidas -- el
        // caso real que motivo esto.
        $fewMatches = $this->makePlayerWithMatches($season->id, 2, 13);
        // Cumple ambos minimos.
        $qualifies = $this->makePlayerWithMatches($season->id, PlayerRankCalculator::MIN_MATCHES, 3);

        $response = $this->get(route('specialties.efficiency', ['server' => $this->server->slug]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $this->assertNull($rows->firstWhere('player.guid', $fewMatches->guid));
        $this->assertNotNull($rows->firstWhere('player.guid', $qualifies->guid));
    }

    public function test_matches_played_by_player_counts_distinct_matches(): void
    {
        $season = Season::current();
        $player = $this->makePlayerWithMatches($season->id, 4, 2);
        $matchIds = GameMatch::forSeason($season->id)->pluck('id');

        $counts = PlayerRankCalculator::matchesPlayedByPlayer($this->server->id, $matchIds);

        $this->assertSame(4, $counts[$player->id]);
    }

    public function test_headshots_page_marks_inactive_players_the_same_way_as_rango(): void
    {
        $season = Season::current();
        $active = $this->makePlayerWithMatches($season->id, PlayerRankCalculator::MIN_MATCHES, 3, headshots: true, roundMinutes: 60);
        $active->update(['last_seen_at' => now()]);
        $inactive = $this->makePlayerWithMatches($season->id, PlayerRankCalculator::MIN_MATCHES, 3, headshots: true, roundMinutes: 1);
        $inactive->update(['last_seen_at' => now()->subDays(20)]);

        $response = $this->get(route('specialties.headshots', ['server' => $this->server->slug]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $inactiveRow = $rows->firstWhere('player.guid', $inactive->guid);
        $activeRow = $rows->firstWhere('player.guid', $active->guid);

        $this->assertTrue($inactiveRow->inactive);
        $this->assertFalse($activeRow->inactive);
    }
}
