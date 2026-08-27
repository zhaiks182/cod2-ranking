<?php

namespace Tests\Feature\Specialties;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\PlayerServerStat;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtrasSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    /**
     * A "real" match that reached a real conclusion (13 rounds) -- GameMatch::forSeason()
     * excludes matches abandoned without a real result (scopeAbandonedWithoutConclusion,
     * already in this codebase from an earlier task), so a match with 0 rounds and
     * ended_at set would be silently filtered out regardless of the season it's in.
     * Same pattern already used by tests/Feature/Specialties/GroupASeasonTest.php.
     */
    private function match(int $seasonId): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => $seasonId,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
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

        return $match;
    }

    /**
     * A real Kill by $attacker against $victim in the first round of $match -- used so
     * the "Kills totales" column (fed by KillAggregator::aggregate() scoped to the same
     * $matchIds as the rest of the season-specific branch) has a known, non-zero value
     * to assert against. Same field shape as GroupDSeasonTest::rangoMatch()'s makeKill().
     */
    private function killInMatch(GameMatch $match, Player $attacker, Player $victim): void
    {
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
            'hitloc' => 'torso_upper',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);
    }

    public function test_bombs_excludes_old_season_and_all_falls_back_to_the_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $victim = Player::create(['guid' => 112, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $oldMatch = $this->match($oldSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $oldMatch->id, 'bomb_plants' => 3, 'bomb_defuses' => 0]);
        $this->killInMatch($oldMatch, $player, $victim);

        // Total historico de ANTES de este feature -- nunca tuvo fila en
        // player_match_extras, solo vive en el acumulador plano (simula datos reales
        // de antes del deploy, que "todo el historial" nunca debe perder).
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'bomb_plants' => 10, 'bomb_defuses' => 2]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $newMatch = $this->match($newSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $newMatch->id, 'bomb_plants' => 5, 'bomb_defuses' => 1]);
        $this->killInMatch($newMatch, $player, $victim);
        $this->killInMatch($newMatch, $player, $victim);
        $this->killInMatch($newMatch, $player, $victim);

        $response = $this->get(route('specialties.bombs', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertNotNull($row);
        $this->assertSame(5, $row->value); // solo la temporada activa (nueva)
        $this->assertSame(3, $row->kills); // "Kills totales" -- solo los 3 kills de la temporada activa, no los de la vieja

        $responseAll = $this->get(route('specialties.bombs', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertSame(10, $rowAll->value); // 'all' lee PlayerServerStat, no 3+5
    }

    public function test_damage_excludes_old_season_and_all_falls_back_to_the_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $victim = Player::create(['guid' => 112, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $oldMatch = $this->match($oldSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $oldMatch->id, 'damage_dealt' => 300]);
        $this->killInMatch($oldMatch, $player, $victim);
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'damage_dealt' => 1000]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $newMatch = $this->match($newSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $newMatch->id, 'damage_dealt' => 450]);
        $this->killInMatch($newMatch, $player, $victim);
        $this->killInMatch($newMatch, $player, $victim);

        $response = $this->get(route('specialties.damage', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertNotNull($row);
        $this->assertSame(number_format(450), $row->value);
        $this->assertSame(2, $row->kills); // "Kills totales" -- solo los 2 kills de la temporada activa

        $responseAll = $this->get(route('specialties.damage', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertSame(number_format(1000), $rowAll->value);
    }

    public function test_disconnects_excludes_old_season_and_all_falls_back_to_the_lifetime_total(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $victim = Player::create(['guid' => 112, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $oldMatch = $this->match($oldSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $oldMatch->id, 'mid_round_disconnects' => 2]);
        $this->killInMatch($oldMatch, $player, $victim);
        PlayerServerStat::create(['player_id' => $player->id, 'server_id' => $this->server->id, 'mid_round_disconnects' => 7]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);
        $newMatch = $this->match($newSeason->id);
        PlayerMatchExtra::create(['player_id' => $player->id, 'match_id' => $newMatch->id, 'mid_round_disconnects' => 3]);
        $this->killInMatch($newMatch, $player, $victim);

        $response = $this->get(route('specialties.disconnects', ['server' => $this->server->slug]));
        $response->assertOk();
        $row = collect($response->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertNotNull($row);
        $this->assertSame(3, $row->value);
        $this->assertSame(1, $row->kills); // "Kills totales" -- solo el kill de la temporada activa

        $responseAll = $this->get(route('specialties.disconnects', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->first(fn ($r) => $r->player->id === $player->id);
        $this->assertSame(7, $rowAll->value);
    }

    public function test_bombs_page_shows_the_season_selector(): void
    {
        $response = $this->get(route('specialties.bombs', ['server' => $this->server->slug]));
        $response->assertOk();
        $response->assertSee('specialty-season-dropdown', false);
    }
}
