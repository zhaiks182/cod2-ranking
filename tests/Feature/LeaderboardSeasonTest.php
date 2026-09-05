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

class LeaderboardSeasonTest extends TestCase
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

    /** Partida real que llegó a 13 rondas (cuenta) — crea 1 kill de $attacker contra $victim. */
    private function realMatch(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id,
                'match_id' => $match->id,
                'map' => $map,
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
            'victim_team' => 'axis',
            'weapon' => 'weapon_mp44',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'hitloc' => 'head',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    /** Partida abandonada: solo 2 rondas, sin MatchEnd -- no debe contar. */
    private function abandonedMatch(int $seasonId, Player $attacker, Player $victim, string $map = 'mp_toujane_fix'): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id,
            'season_id' => $seasonId,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $round = Round::create([
            'server_id' => $this->server->id,
            'match_id' => $match->id,
            'map' => $map,
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

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
            'hitloc' => 'head',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_ranking_without_season_param_shows_only_the_active_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->kills); // solo las 2 de Temporada 2 (la activa), no la 1 de Temporada 1
    }

    public function test_ranking_with_season_all_shows_every_season_combined(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'season' => 'all']));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2, $row->kills); // las 2 partidas, de las 2 temporadas
    }

    public function test_ranking_excludes_abandoned_matches_in_any_season(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($season->id, $attacker, $victim);
        $this->abandonedMatch($season->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(1, $row->kills); // solo la real, la abandonada no suma
    }

    public function test_ranking_for_a_specific_closed_season(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($oldSeason->id, $attacker, $victim);
        $this->realMatch($oldSeason->id, $attacker, $victim);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'season' => $oldSeason->id]));

        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertSame(2, $row->kills); // las 2 de Temporada 1, no la de Temporada 2
    }

    /**
     * Finding 1 del final review: en una pestana de mapa multi-sesion, la tabla
     * (aggregateFromKills) y el panel Axis/Allies (que ya se narrowea con $from/$to)
     * tenian que mostrar lo mismo -- la tabla sumaba TODA la temporada mientras el
     * panel solo mostraba la ultima sesion. Esta partida crea dos sesiones del mismo
     * mapa en dias distintos dentro de la misma temporada: 3 kills el dia viejo, 5
     * kills el dia mas reciente. La tabla debe mostrar solo 5 (la sesion mas
     * reciente), no 8 (la suma de toda la temporada).
     */
    public function test_ranking_table_on_multi_session_map_matches_latest_session_only(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);

        $oldMatch = $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');
        $oldMatch->update(['started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)]);
        $oldMatch->rounds()->update(['started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)]);
        // 2 kills mas en la sesion vieja (3 en total con la del helper).
        for ($i = 0; $i < 2; $i++) {
            Kill::create([
                'round_id' => $oldMatch->rounds()->first()->id,
                'match_id' => $oldMatch->id,
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
                'hitloc' => 'head',
                'is_headshot' => false,
                'is_grenade' => false,
                'is_suicide' => false,
                'is_teamkill' => false,
                'occurred_at' => now()->subDays(3),
            ]);
        }

        $newMatch = $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');
        // 4 kills mas en la sesion reciente (5 en total con la del helper).
        for ($i = 0; $i < 4; $i++) {
            Kill::create([
                'round_id' => $newMatch->rounds()->first()->id,
                'match_id' => $newMatch->id,
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
                'hitloc' => 'head',
                'is_headshot' => false,
                'is_grenade' => false,
                'is_suicide' => false,
                'is_teamkill' => false,
                'occurred_at' => now(),
            ]);
        }

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'map' => 'mp_toujane']));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(5, $row->kills); // solo la sesion mas reciente, no las 8 de toda la temporada
    }

    public function test_ranking_table_honors_an_explicit_from_param_for_a_specific_session(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);

        $oldDate = now()->subDays(3);
        $oldMatch = $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');
        $oldMatch->update(['started_at' => $oldDate, 'ended_at' => $oldDate]);
        $oldMatch->rounds()->update(['started_at' => $oldDate, 'ended_at' => $oldDate]);
        // 2 kills mas en la sesion vieja (3 en total con la del helper).
        for ($i = 0; $i < 2; $i++) {
            Kill::create([
                'round_id' => $oldMatch->rounds()->first()->id,
                'match_id' => $oldMatch->id,
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
                'hitloc' => 'head',
                'is_headshot' => false,
                'is_grenade' => false,
                'is_suicide' => false,
                'is_teamkill' => false,
                'occurred_at' => $oldDate,
            ]);
        }

        $newMatch = $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');
        // 4 kills mas en la sesion reciente (5 en total con la del helper).
        for ($i = 0; $i < 4; $i++) {
            Kill::create([
                'round_id' => $newMatch->rounds()->first()->id,
                'match_id' => $newMatch->id,
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
                'hitloc' => 'head',
                'is_headshot' => false,
                'is_grenade' => false,
                'is_suicide' => false,
                'is_teamkill' => false,
                'occurred_at' => now(),
            ]);
        }

        $oldDateStr = $oldDate->toDateString();
        $response = $this->get(route('leaderboard', [
            'server' => $this->server->slug,
            'map' => 'mp_toujane',
            'from' => $oldDateStr,
            'to' => $oldDateStr,
        ]));

        $response->assertOk();
        $this->assertTrue($response->viewData('usingDateFilter'));
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(3, $row->kills); // la sesion vieja explicita, no la mas reciente (5) ni las 8 combinadas
    }

    public function test_ranking_general_tab_honors_from_to_without_any_map_selected(): void
    {
        // Mismo mecanismo y misma UI que /partidas: el filtro de fecha manual
        // (Desde/Hasta) tiene que funcionar tambien en "General" (sin mapa
        // seleccionado), no solo dentro de la pestaña de un mapa puntual.
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);

        $oldDate = now()->subDays(3);
        $oldMatch = $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');
        $oldMatch->update(['started_at' => $oldDate, 'ended_at' => $oldDate]);
        $oldMatch->rounds()->update(['started_at' => $oldDate, 'ended_at' => $oldDate]);

        $this->realMatch($season->id, $attacker, $victim, 'mp_railyard');

        $oldDateStr = $oldDate->toDateString();
        $response = $this->get(route('leaderboard', [
            'server' => $this->server->slug,
            'from' => $oldDateStr,
            'to' => $oldDateStr,
        ]));

        $response->assertOk();
        $this->assertTrue($response->viewData('usingDateFilter'));
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $attacker->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row->kills); // solo la partida del dia viejo, la de railyard (hoy) queda afuera
    }

    public function test_ranking_table_honors_an_arbitrary_date_with_no_matching_data(): void
    {
        // El filtro acepta cualquier fecha (igual que /partidas), sin validarla contra
        // sesiones conocidas -- una fecha real pero sin datos simplemente muestra la
        // tabla vacia, no cae de vuelta a la sesion mas reciente en silencio.
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');

        $response = $this->get(route('leaderboard', [
            'server' => $this->server->slug,
            'map' => 'mp_toujane',
            'from' => '2020-01-01',
            'to' => '2020-01-01',
        ]));

        $response->assertOk();
        $this->assertTrue($response->viewData('usingDateFilter'));
        $this->assertTrue(collect($response->viewData('rows'))->isEmpty());
    }

    /**
     * Columna "Partidas" (2026-09-05, a pedido del dueño) -- mismo proxy de
     * "jugó esta partida" que ya usa PlayerRankCalculator para el mínimo de
     * /rango, reusado acá en vez de inventar un conteo aparte. Una partida
     * abandonada (sin resultado real) no debe sumar.
     */
    public function test_ranking_shows_matches_played_per_player(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($season->id, $attacker, $victim);
        $this->realMatch($season->id, $attacker, $victim);
        $this->abandonedMatch($season->id, $attacker, $victim);

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug]));

        $row = collect($response->viewData('rows'))->firstWhere('player.guid', $attacker->guid);
        $this->assertSame(2, $row->matches_played);
    }

    /** El filtro de mapa también debe acotar la columna "Partidas", no solo kills/muertes. */
    public function test_matches_played_respects_the_selected_map_tab(): void
    {
        $season = Season::current();
        $attacker = Player::create(['guid' => 111, 'last_name' => 'Attacker', 'last_name_plain' => 'Attacker']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $this->realMatch($season->id, $attacker, $victim, 'mp_toujane_fix');
        $this->realMatch($season->id, $attacker, $victim, 'mp_burgundy_fix');

        $response = $this->get(route('leaderboard', ['server' => $this->server->slug, 'map' => 'mp_toujane']));

        $row = collect($response->viewData('rows'))->firstWhere('player.guid', $attacker->guid);
        $this->assertSame(1, $row->matches_played);
    }
}
