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
 * TDD gap closed retroactively: killStreaks() (/rachas-de-bajas) was scoped by
 * season in commit cb08eaa with ZERO test coverage — this mirrors the pattern
 * GroupDSeasonTest.php already uses for its sibling method streaks() (racha de
 * mapas), applied to killStreaks() (racha de bajas) instead.
 */
class KillStreaksSeasonTest extends TestCase
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

    private function makeKill(Round $round, GameMatch $match, Player $attacker, Player $victim, $occurredAt): Kill
    {
        return Kill::create([
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
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_kill_streaks_excludes_old_season_kills(): void
    {
        $oldSeason = Season::current();
        $attacker = Player::create(['guid' => 901, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 902, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Partida vieja: ended_at null a proposito (mismo motivo que playtime/clutches
        // en GroupDSeasonTest) -- evita scopeAbandonedWithoutConclusion() sin tener que
        // construir 13 rondas reales solo para esta prueba.
        $oldMatch = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $oldSeason->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null,
        ]);
        $oldRound = Round::create([
            'server_id' => $this->server->id, 'match_id' => $oldMatch->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
        ]);

        // Racha de 5 bajas seguidas en la temporada vieja, cerrada por una muerte
        // propia (para que no se "pegue" con la racha nueva al mirar season=all).
        for ($i = 0; $i < 5; $i++) {
            $this->makeKill($oldRound, $oldMatch, $attacker, $victim, now()->addSeconds($i));
        }
        $this->makeKill($oldRound, $oldMatch, $victim, $attacker, now()->addSeconds(5));

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $newMatch = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $newSeason->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null,
        ]);
        $newRound = Round::create([
            'server_id' => $this->server->id, 'match_id' => $newMatch->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
        ]);

        // Racha de 3 bajas seguidas en la temporada nueva, sin ninguna muerte despues.
        for ($i = 0; $i < 3; $i++) {
            $this->makeKill($newRound, $newMatch, $attacker, $victim, now()->addSeconds(10 + $i));
        }

        $response = $this->get(route('specialties.streaks-kills', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->guid === $attacker->guid);
        $this->assertNotNull($row);
        $this->assertSame(3, $row->value); // solo la temporada activa

        $responseAll = $this->get(route('specialties.streaks-kills', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->guid === $attacker->guid);
        $this->assertSame(5, $rowAll->value); // la racha de 5 de la temporada vieja sigue siendo la mejor
    }
}
