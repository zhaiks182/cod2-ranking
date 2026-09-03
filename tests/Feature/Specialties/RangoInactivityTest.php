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

/**
 * Jugador inactivo movido al final de /rango, en gris (2026-09-02, a pedido
 * del dueño) -- dos condiciones a la vez: 15+ dias sin jugar Y horas
 * jugadas esta temporada por debajo del promedio general de la temporada
 * vigente. Solo aplica mirando la temporada activa.
 */
class RangoInactivityTest extends TestCase
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

    /** Partida de $durationMinutes de duracion, 9 veces, con 1 kill/1 muerte reales por partida. */
    private function makeQualifiedPlayer(int $seasonId, int $durationMinutes, \Carbon\Carbon $lastSeenAt): Player
    {
        $player = Player::create(['guid' => random_int(100000, 999999), 'last_name' => 'P', 'last_name_plain' => 'P', 'last_seen_at' => $lastSeenAt]);
        $filler = Player::create(['guid' => random_int(100000, 999999), 'last_name' => 'F', 'last_name_plain' => 'F']);
        $teammate = Player::create(['guid' => random_int(100000, 999999), 'last_name' => 'T', 'last_name_plain' => 'T']);

        for ($m = 0; $m < 9; $m++) {
            $match = GameMatch::create([
                'server_id' => $this->server->id, 'season_id' => $seasonId,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);

            // 13 rondas reales (GameMatch::forSeason()/reachedConclusion() exige
            // 13+ rondas o un evento match_end para que la partida cuente) -- la
            // primera dura $durationMinutes (a mano, para controlar las horas
            // jugadas via PlaytimeCalculator), el resto duracion nominal.
            $rounds = [];
            for ($i = 1; $i <= 13; $i++) {
                $rounds[] = Round::create([
                    'server_id' => $this->server->id, 'match_id' => $match->id,
                    'map' => 'mp_toujane_fix', 'gametype' => 'sd',
                    'started_at' => now(), 'ended_at' => $i === 1 ? now()->addMinutes($durationMinutes) : now(),
                    'winner_guids' => [$player->guid, $teammate->guid],
                ]);
            }

            Kill::create([
                'round_id' => $rounds[0]->id, 'match_id' => $match->id,
                'attacker_player_id' => $player->id, 'attacker_guid' => $player->guid, 'attacker_name' => $player->last_name, 'attacker_team' => 'allies',
                'victim_player_id' => $filler->id, 'victim_guid' => $filler->guid, 'victim_name' => $filler->last_name, 'victim_team' => 'axis',
                'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
            ]);
        }

        return $player;
    }

    public function test_a_player_inactive_15_days_and_below_average_hours_is_marked_and_moved_last(): void
    {
        $season = Season::current();
        // Jugador activo: recien visto, muchas horas (60 min x 9 = 540 min).
        $active = $this->makeQualifiedPlayer($season->id, 60, now());
        // Jugador inactivo: 20 dias sin jugar, pocas horas (1 min x 9 = 9 min) --
        // muy por debajo del promedio de los dos (274.5 min).
        $inactive = $this->makeQualifiedPlayer($season->id, 1, now()->subDays(20));

        $response = $this->get(route('rango', ['server' => $this->server->slug]));

        $response->assertOk();
        $rows = collect($response->viewData('rows'));
        $inactiveRow = $rows->firstWhere('player.guid', $inactive->guid);
        $activeRow = $rows->firstWhere('player.guid', $active->guid);

        $this->assertTrue($inactiveRow->inactive);
        $this->assertFalse($activeRow->inactive);
        // El inactivo queda al final de la lista, sin importar su score.
        $this->assertSame($rows->count() - 1, $rows->search($inactiveRow));
    }

    public function test_recently_active_player_with_few_hours_is_not_marked_inactive(): void
    {
        $season = Season::current();
        $active = $this->makeQualifiedPlayer($season->id, 60, now());
        // Pocas horas, pero jugo HACE POCO (no cumple la primera condicion).
        $recentButFewHours = $this->makeQualifiedPlayer($season->id, 1, now()->subDays(1));

        $response = $this->get(route('rango', ['server' => $this->server->slug]));

        $rows = collect($response->viewData('rows'));
        $row = $rows->firstWhere('player.guid', $recentButFewHours->guid);

        $this->assertFalse($row->inactive);
    }

    public function test_inactivity_does_not_apply_when_viewing_a_closed_season(): void
    {
        $oldSeason = Season::current();
        $inactive = $this->makeQualifiedPlayer($oldSeason->id, 1, now()->subDays(20));
        $this->makeQualifiedPlayer($oldSeason->id, 60, now());
        $oldSeason->update(['ended_at' => now()]);
        Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $response = $this->get(route('rango', ['server' => $this->server->slug, 'season' => $oldSeason->id]));

        $rows = collect($response->viewData('rows'));
        $row = $rows->firstWhere('player.guid', $inactive->guid);

        $this->assertFalse($row->inactive ?? false);
    }
}
