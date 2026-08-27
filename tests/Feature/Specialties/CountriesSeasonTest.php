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

class CountriesSeasonTest extends TestCase
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

    /** Crea una partida concluida (13 rondas) para la temporada indicada. */
    private function createConcludedMatch(int $seasonId): GameMatch
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
            ]);
        }

        return $match;
    }

    public function test_countries_filters_by_season(): void
    {
        $oldSeason = Season::current();

        // Player 1: active only in old season, with known IP (Google DNS)
        $player1 = Player::create([
            'guid' => 111,
            'last_name' => 'OldPlayer',
            'last_name_plain' => 'OldPlayer',
            'ip' => '8.8.8.8',  // Resolves to USA
        ]);

        $oldMatch = $this->createConcludedMatch($oldSeason->id);
        Round::where('match_id', $oldMatch->id)->first()->update(['round_number' => 1]);

        Kill::create([
            'match_id' => $oldMatch->id,
            'round_id' => Round::where('match_id', $oldMatch->id)->first()->id,
            'attacker_player_id' => $player1->id,
            'attacker_guid' => $player1->guid,
            'attacker_name' => $player1->last_name,
            'attacker_team' => 'axis',
            'victim_player_id' => null,
            'victim_guid' => 0,
            'victim_name' => 'Bot',
            'victim_team' => 'allies',
            'weapon' => 'G3',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        // Close old season, open new season
        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        // Player 2: active only in new season, with known IP (Cloudflare DNS)
        $player2 = Player::create([
            'guid' => 222,
            'last_name' => 'NewPlayer',
            'last_name_plain' => 'NewPlayer',
            'ip' => '1.1.1.1',  // Resolves to USA
        ]);

        $newMatch = $this->createConcludedMatch($newSeason->id);
        Round::where('match_id', $newMatch->id)->first()->update(['round_number' => 1]);

        Kill::create([
            'match_id' => $newMatch->id,
            'round_id' => Round::where('match_id', $newMatch->id)->first()->id,
            'attacker_player_id' => $player2->id,
            'attacker_guid' => $player2->guid,
            'attacker_name' => $player2->last_name,
            'attacker_team' => 'axis',
            'victim_player_id' => null,
            'victim_guid' => 0,
            'victim_name' => 'Bot',
            'victim_team' => 'allies',
            'weapon' => 'G3',
            'damage' => 100,
            'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false,
            'is_grenade' => false,
            'is_suicide' => false,
            'is_teamkill' => false,
            'occurred_at' => now(),
        ]);

        // Test default (current season only)
        $response = $this->get(route('specialties.countries'));
        $response->assertOk();

        $countries = collect($response->viewData('countries'));
        $totalWithCountry = $response->viewData('totalWithCountry');

        // Only player2 (new season) should appear
        $this->assertSame(1, $totalWithCountry);
        $countryGroup = $countries->first();
        $this->assertNotNull($countryGroup);
        $playerIds = $countryGroup->players->pluck('id')->toArray();
        $this->assertContains($player2->id, $playerIds);
        $this->assertNotContains($player1->id, $playerIds);

        // Test with season=all
        $responseAll = $this->get(route('specialties.countries', ['season' => 'all']));
        $responseAll->assertOk();

        $countriesAll = collect($responseAll->viewData('countries'));
        $totalWithCountryAll = $responseAll->viewData('totalWithCountry');

        // Both players should appear (all seasons)
        $this->assertSame(2, $totalWithCountryAll);
        $allPlayerIds = $countriesAll->flatMap(fn ($c) => $c->players)->pluck('id')->toArray();
        $this->assertContains($player1->id, $allPlayerIds);
        $this->assertContains($player2->id, $allPlayerIds);
    }
}
