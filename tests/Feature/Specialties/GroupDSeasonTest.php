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

class GroupDSeasonTest extends TestCase
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
     * Partida COMPLETA (13 rondas, todas con winner_guids = [$winner->guid], ended_at
     * puesto) con un solo Kill de $winner contra $loser -- satisface a la vez:
     * - reachedConclusion() (>=13 rondas), asi que no cae en abandonedWithoutConclusion()
     *   ni queda excluida de resolveSeason()/forSeason().
     * - TeamSideAnalyzer::winningRosterGuids() (exige 2+ rondas con winner_guids no
     *   nulo) -- con las 13 rondas ganadas por el mismo roster, cluster A se queda con
     *   las 13 y cluster B en 0, asi que winningRosterGuids() devuelve [$winner->guid]
     *   limpio (confirmado leyendo TeamSideAnalyzer::clusterRoundWinners() antes de
     *   construir esto).
     * - El proxy de "jugo esta partida" de winRate()/streaks()/rango() (aparecer como
     *   atacante o victima en al menos un Kill de la partida).
     */
    private function realCompletedMatch(int $seasonId, Player $winner, Player $loser): GameMatch
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
                'winner_guids' => [$winner->guid],
            ]);
        }

        $round = $match->rounds()->first();

        Kill::create([
            'round_id' => $round->id,
            'match_id' => $match->id,
            'attacker_player_id' => $winner->id,
            'attacker_guid' => $winner->guid,
            'attacker_name' => $winner->last_name,
            'attacker_team' => 'allies',
            'victim_player_id' => $loser->id,
            'victim_guid' => $loser->guid,
            'victim_name' => $loser->last_name,
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

        return $match;
    }

    public function test_maps_won_excludes_old_season_matches(): void
    {
        $oldSeason = Season::current();
        $winner = Player::create(['guid' => 301, 'last_name' => 'W', 'last_name_plain' => 'W']);
        $loser = Player::create(['guid' => 302, 'last_name' => 'L', 'last_name_plain' => 'L']);

        $this->realCompletedMatch($oldSeason->id, $winner, $loser);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realCompletedMatch($newSeason->id, $winner, $loser);
        $this->realCompletedMatch($newSeason->id, $winner, $loser);

        $response = $this->get(route('specialties.maps-won', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value); // solo la temporada activa

        $responseAll = $this->get(route('specialties.maps-won', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertSame(3, $rowAll->value); // las 2 temporadas
    }

    public function test_streaks_excludes_old_season_matches(): void
    {
        $oldSeason = Season::current();
        $winner = Player::create(['guid' => 401, 'last_name' => 'W', 'last_name_plain' => 'W']);
        $loser = Player::create(['guid' => 402, 'last_name' => 'L', 'last_name_plain' => 'L']);

        $this->realCompletedMatch($oldSeason->id, $winner, $loser);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $this->realCompletedMatch($newSeason->id, $winner, $loser);
        $this->realCompletedMatch($newSeason->id, $winner, $loser);

        $response = $this->get(route('specialties.streaks', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->best); // solo la temporada activa

        $responseAll = $this->get(route('specialties.streaks', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertSame(3, $rowAll->best); // las 2 temporadas
    }

    public function test_win_rate_excludes_old_season_matches(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 501, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 502, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Temporada vieja: attacker JUEGA pero PIERDE (el roster de victim gana).
        $this->realCompletedMatch($oldSeason->id, $victim, $attacker);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        // Temporada nueva: attacker gana las 3 partidas (>= minMaps=3).
        $this->realCompletedMatch($newSeason->id, $attacker, $victim);
        $this->realCompletedMatch($newSeason->id, $attacker, $victim);
        $this->realCompletedMatch($newSeason->id, $attacker, $victim);

        $response = $this->get(route('specialties.win-rate', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $attacker->guid);
        $this->assertNotNull($row);
        $this->assertSame(3, $row->played);
        $this->assertSame(3, $row->won);
        $this->assertSame(100.0, $row->rate); // solo temporada activa: 3/3

        $responseAll = $this->get(route('specialties.win-rate', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $attacker->guid);
        $this->assertSame(4, $rowAll->played);
        $this->assertSame(3, $rowAll->won);
        $this->assertSame(75.0, $rowAll->rate); // las 2 temporadas: 3/4
    }

    /**
     * Partida ganada bajo un guid que el jugador ya no tiene -- simula el
     * estado exacto que deja PlayerMerger (ver CLAUDE.md, "Fusionar
     * jugadores"): attacker_player_id/victim_player_id repuntados al
     * jugador destino, pero attacker_guid/victim_guid SIN reescribir (el
     * registro fiel de lo que el log dijo en su momento). Bug real
     * reportado por el dueño (2026-09-02): tras fusionar a "DESTINATION #
     * ZHAIKS" perdió win rate -- mapsWon()/streaks()/winRate() tallyaban
     * por el guid crudo de winner_guids y despues buscaban `Player::where
     * guid=...`, que ya no existe para el guid viejo (la fila fuente se
     * borra al fusionar) -- toda la actividad bajo la identidad anterior
     * quedaba huerfana, invisible.
     */
    private function matchWonUnderOldGuid(int $seasonId, Player $winner, int $oldGuidUsedThisMatch, Player $loser): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $seasonId,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd',
                'started_at' => now(), 'ended_at' => now(),
                'winner_guids' => [$oldGuidUsedThisMatch],
            ]);
        }

        Kill::create([
            'round_id' => $match->rounds()->first()->id, 'match_id' => $match->id,
            'attacker_player_id' => $winner->id, 'attacker_guid' => $oldGuidUsedThisMatch, 'attacker_name' => 'Old Name', 'attacker_team' => 'allies',
            'victim_player_id' => $loser->id, 'victim_guid' => $loser->guid, 'victim_name' => $loser->last_name, 'victim_team' => 'axis',
            'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_maps_won_counts_a_match_won_under_a_guid_the_player_had_before_being_merged(): void
    {
        $winner = Player::create(['guid' => 601, 'last_name' => 'W', 'last_name_plain' => 'W']);
        $loser = Player::create(['guid' => 602, 'last_name' => 'L', 'last_name_plain' => 'L']);
        $this->matchWonUnderOldGuid(Season::current()->id, $winner, 999601, $loser);

        $response = $this->get(route('specialties.maps-won', ['server' => $this->server->slug]));
        $response->assertOk();

        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertNotNull($row);
        $this->assertSame(1, $row->value);
    }

    public function test_streaks_counts_a_match_won_under_a_guid_the_player_had_before_being_merged(): void
    {
        $winner = Player::create(['guid' => 611, 'last_name' => 'W', 'last_name_plain' => 'W']);
        $loser = Player::create(['guid' => 612, 'last_name' => 'L', 'last_name_plain' => 'L']);
        $this->matchWonUnderOldGuid(Season::current()->id, $winner, 999611, $loser);
        $this->matchWonUnderOldGuid(Season::current()->id, $winner, 999611, $loser);

        $response = $this->get(route('specialties.streaks', ['server' => $this->server->slug]));
        $response->assertOk();

        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->best);
        $this->assertSame(2, $row->current);
    }

    public function test_win_rate_counts_a_match_won_under_a_guid_the_player_had_before_being_merged(): void
    {
        $winner = Player::create(['guid' => 621, 'last_name' => 'W', 'last_name_plain' => 'W']);
        $loser = Player::create(['guid' => 622, 'last_name' => 'L', 'last_name_plain' => 'L']);
        $this->matchWonUnderOldGuid(Season::current()->id, $winner, 999621, $loser);
        $this->matchWonUnderOldGuid(Season::current()->id, $winner, 999621, $loser);
        $this->matchWonUnderOldGuid(Season::current()->id, $winner, 999621, $loser);

        $response = $this->get(route('specialties.win-rate', ['server' => $this->server->slug]));
        $response->assertOk();

        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $winner->guid);
        $this->assertNotNull($row);
        $this->assertSame(3, $row->played);
        $this->assertSame(3, $row->won);
        $this->assertSame(100.0, $row->rate);
    }

    /**
     * Partida completa (13 rondas, ganadas por el roster [$a, $r]) con $aKills/$aDeaths
     * kills/muertes de $a y $rKills/$rDeaths de $r, todos contra $v -- $v nunca es el
     * foco de las aserciones, solo hace de saco de boxeo para que $a y $r puedan tener
     * kills/muertes reales sin matarse entre si (lo que los marcaria como fuego amigo).
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

    public function test_rango_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $a = Player::create(['guid' => 601, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $r = Player::create(['guid' => 602, 'last_name' => 'R', 'last_name_plain' => 'R']);
        $v = Player::create(['guid' => 603, 'last_name' => 'V', 'last_name_plain' => 'V']);

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

        $response = $this->get(route('rango', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $a->guid);
        $this->assertNotNull($row);
        $this->assertSame(2.0, $row->kd); // solo temporada activa: 40/20

        $responseAll = $this->get(route('rango', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $a->guid);
        $this->assertSame(1.33, $rowAll->kd); // las 2 temporadas: 80/60 = 1.333... -> 1.33
    }

    public function test_playtime_excludes_old_season_rounds(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 701, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 702, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Partida vieja con 1 ronda de 60s -- ended_at del MATCH queda null (partida
        // "en curso" a proposito) para que no la agarre scopeAbandonedWithoutConclusion()
        // (esa requiere ended_at del match no-nulo + <13 rondas + sin MatchEnd;), sin
        // tener que construir 13 rondas reales solo para esta prueba de duracion.
        $oldMatch = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $oldSeason->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null,
        ]);
        $oldRound = Round::create([
            'server_id' => $this->server->id, 'match_id' => $oldMatch->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now()->subSeconds(60), 'ended_at' => now(),
        ]);
        Kill::create([
            'round_id' => $oldRound->id, 'match_id' => $oldMatch->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
            'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $newMatch = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $newSeason->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null,
        ]);
        $newRound = Round::create([
            'server_id' => $this->server->id, 'match_id' => $newMatch->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now()->subSeconds(120), 'ended_at' => now(),
        ]);
        Kill::create([
            'round_id' => $newRound->id, 'match_id' => $newMatch->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
            'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

        $response = $this->get(route('specialties.playtime', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $attacker->guid);
        $this->assertNotNull($row);
        $this->assertSame('2 min', $row->value); // solo la ronda de la temporada activa (120s)

        $responseAll = $this->get(route('specialties.playtime', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $attacker->guid);
        $this->assertSame('3 min', $rowAll->value); // las 2 rondas: 60s + 120s = 180s
    }

    /**
     * Ronda con roster ganador [$survivorGuid, ...$victimGuids] donde cada guid de
     * $victimGuids muere durante la ronda (Kill con ese guid como victima) -- deja a
     * $survivorGuid como unico sobreviviente del roster, un clutch real segun la
     * logica de clutches() (roster de 3+, exactamente 1 sobreviviente). Los guids que
     * mueren son crudos (sin fila en `players`) a proposito -- clutches() nunca los
     * busca por Player, solo los usa para calcular quien sobrevivio.
     */
    private function clutchRound(GameMatch $match, int $survivorGuid, array $victimGuids): void
    {
        $round = Round::create([
            'server_id' => $this->server->id,
            'match_id' => $match->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
            'winner_guids' => array_merge([$survivorGuid], $victimGuids),
        ]);

        foreach ($victimGuids as $i => $vg) {
            Kill::create([
                'round_id' => $round->id,
                'match_id' => $match->id,
                'attacker_player_id' => null,
                'attacker_guid' => 999,
                'attacker_name' => 'Enemy',
                'victim_player_id' => null,
                'victim_guid' => $vg,
                'victim_name' => 'Bystander'.$i,
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

    public function test_clutches_excludes_old_season_rounds(): void
    {
        $oldSeason = Season::current();
        $survivor = Player::create(['guid' => 800, 'last_name' => 'Surv', 'last_name_plain' => 'Surv']);

        // ended_at del match queda null a proposito (mismo motivo que en playtime) --
        // evita scopeAbandonedWithoutConclusion() sin tener que construir 13 rondas
        // reales para esta prueba.
        $oldMatch = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $oldSeason->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null,
        ]);
        $this->clutchRound($oldMatch, 800, [601, 602]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $newMatch = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $newSeason->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null,
        ]);
        $this->clutchRound($newMatch, 800, [611, 612]);
        $this->clutchRound($newMatch, 800, [613, 614]);

        $response = $this->get(route('specialties.clutches', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $survivor->guid);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value); // solo los 2 clutches de la temporada activa

        $responseAll = $this->get(route('specialties.clutches', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $survivor->guid);
        $this->assertSame(3, $rowAll->value); // los 3 clutches de las 2 temporadas
    }
}
