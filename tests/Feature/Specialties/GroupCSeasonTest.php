<?php

namespace Tests\Feature\Specialties;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupCSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // peakTimes() usa hour()/dayofweek(), funciones nativas de MySQL/MariaDB
        // (el motor real de produccion) que SQLite (el motor de los tests, ver
        // phpunit.xml) no trae por defecto -- se registran aca como funciones de
        // usuario minimas equivalentes, solo para poder ejercitar esa ruta real
        // en este test, sin tocar el controller ni nada de produccion.
        DB::connection()->getPdo()->sqliteCreateFunction('hour', fn ($datetime) => (int) date('G', strtotime($datetime)));
        DB::connection()->getPdo()->sqliteCreateFunction('dayofweek', fn ($datetime) => ((int) date('w', strtotime($datetime))) + 1);

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
     * Partida real que llega a 13 rondas (cuenta como concluida) con $count kills
     * de $attacker contra $victim, con los flags que pida cada caso. Todos los
     * kills quedan en la primera ronda, con occurred_at = now() siempre.
     */
    private function realMatchWithKills(
        int $seasonId,
        Player $attacker,
        Player $victim,
        int $count,
        string $weapon = 'weapon_mp44',
        bool $isGrenade = false,
        bool $isTeamkill = false,
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

        for ($i = 0; $i < $count; $i++) {
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
                'weapon' => $weapon,
                'damage' => 100,
                'mod' => $isGrenade ? 'MOD_GRENADE' : 'MOD_RIFLE_BULLET',
                'hitloc' => 'torso_upper',
                'is_headshot' => false,
                'is_grenade' => $isGrenade,
                'is_suicide' => false,
                'is_teamkill' => $isTeamkill,
                'occurred_at' => now(),
            ]);
        }

        return $match;
    }

    /**
     * grenadeDeaths() tiene una excepcion real (no mecanica): el campo "kills" de
     * referencia por fila ya no lee PlayerServerStat (acumulado de por vida), sino
     * un conteo en vivo via KillAggregator::aggregate() -- este test cubre tanto el
     * conteo de muertes por nade como ese campo de referencia, los dos scopeados.
     */
    public function test_grenade_deaths_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Temporada vieja: 1 muerte por nade de la victima + 1 kill propio de la
        // victima (para poblar el campo "kills" de referencia de esa temporada).
        $this->realMatchWithKills($oldSeason->id, $attacker, $victim, count: 1, isGrenade: true);
        $this->realMatchWithKills($oldSeason->id, $victim, $attacker, count: 1);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        // Temporada nueva: 2 muertes por nade de la victima + 3 kills propios.
        $this->realMatchWithKills($newSeason->id, $attacker, $victim, count: 2, isGrenade: true);
        $this->realMatchWithKills($newSeason->id, $victim, $attacker, count: 3);

        $response = $this->get(route('specialties.grenade-deaths', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $victim->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value); // solo la temporada activa
        $this->assertSame(3, $row->kills); // "kills" de referencia tambien scopeado

        $responseAll = $this->get(route('specialties.grenade-deaths', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $victim->id);
        $this->assertSame(3, $rowAll->value); // 1 + 2
        $this->assertSame(4, $rowAll->kills); // 1 + 3
    }

    public function test_weapons_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKills($oldSeason->id, $attacker, $victim, count: 1, weapon: 'weapon_mp44');

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKills($newSeason->id, $attacker, $victim, count: 2, weapon: 'weapon_mp44');

        $response = $this->get(route('specialties.weapons', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('weapons'))->firstWhere('weapon', 'weapon_mp44');
        $this->assertNotNull($row);
        $this->assertSame(2, $row->uses); // solo la temporada activa

        $responseAll = $this->get(route('specialties.weapons', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('weapons'))->firstWhere('weapon', 'weapon_mp44');
        $this->assertSame(3, $rowAll->uses); // 1 + 2
    }

    public function test_rivalries_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // 2 en la vieja, 4 en la nueva -- ambas cantidades solas ya superan el piso
        // de la rivalidad (having kills_count >= 3), asi que el filtro de temporada
        // es lo unico que puede explicar la diferencia entre los dos valores.
        $this->realMatchWithKills($oldSeason->id, $attacker, $victim, count: 2);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKills($newSeason->id, $attacker, $victim, count: 4);

        $response = $this->get(route('specialties.rivalries', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rivalries'))
            ->first(fn ($r) => $r->nemesis?->id === $attacker->id && $r->victim?->id === $victim->id);
        $this->assertNotNull($row);
        $this->assertSame(4, $row->count); // solo la temporada activa

        $responseAll = $this->get(route('specialties.rivalries', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rivalries'))
            ->first(fn ($r) => $r->nemesis?->id === $attacker->id && $r->victim?->id === $victim->id);
        $this->assertNotNull($rowAll);
        $this->assertSame(6, $rowAll->count); // 2 + 4
    }

    /**
     * Nota del brief: now() en ambas temporadas -- el filtro propio de "ultimos 7
     * dias" de esta pagina es independiente del de temporada, el punto es probar
     * solo que la temporada vieja no aporta, no la ventana de fecha.
     */
    public function test_recent_activity_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKills($oldSeason->id, $attacker, $victim, count: 2);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKills($newSeason->id, $attacker, $victim, count: 5);

        $response = $this->get(route('specialties.recent-activity', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(5, $row->value); // solo la temporada activa

        $responseAll = $this->get(route('specialties.recent-activity', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(7, $rowAll->value); // 2 + 5
    }

    public function test_peak_times_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->realMatchWithKills($oldSeason->id, $attacker, $victim, count: 3);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatchWithKills($newSeason->id, $attacker, $victim, count: 5);

        $response = $this->get(route('specialties.peak-times', ['server' => $this->server->slug]));

        $response->assertOk();
        $this->assertSame(5, collect($response->viewData('byHour'))->sum('value')); // solo la temporada activa
        $this->assertSame(5, collect($response->viewData('byWeekday'))->sum('value'));

        $responseAll = $this->get(route('specialties.peak-times', ['server' => $this->server->slug, 'season' => 'all']));
        $this->assertSame(8, collect($responseAll->viewData('byHour'))->sum('value')); // 3 + 5
        $this->assertSame(8, collect($responseAll->viewData('byWeekday'))->sum('value'));
    }
}
