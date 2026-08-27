<?php

namespace Tests\Feature\Specialties;

use App\Models\ChatMessage;
use App\Models\GameMatch;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupFSeasonTest extends TestCase
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

    public function test_chattiest_excludes_old_season_messages(): void
    {
        $oldSeason = Season::current();
        $player = Player::create(['guid' => 111, 'last_name' => 'ChatPlayer', 'last_name_plain' => 'ChatPlayer']);

        $oldMatch = $this->createConcludedMatch($oldSeason->id);
        ChatMessage::create([
            'server_id' => $this->server->id,
            'match_id' => $oldMatch->id,
            'player_id' => $player->id,
            'guid' => $player->guid,
            'name' => $player->last_name,
            'message' => 'old season message',
            'channel' => 'global',
            'occurred_at' => now(),
        ]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $newMatch = $this->createConcludedMatch($newSeason->id);
        ChatMessage::create([
            'server_id' => $this->server->id,
            'match_id' => $newMatch->id,
            'player_id' => $player->id,
            'guid' => $player->guid,
            'name' => $player->last_name,
            'message' => 'new season message 1',
            'channel' => 'global',
            'occurred_at' => now(),
        ]);
        ChatMessage::create([
            'server_id' => $this->server->id,
            'match_id' => $newMatch->id,
            'player_id' => $player->id,
            'guid' => $player->guid,
            'name' => $player->last_name,
            'message' => 'new season message 2',
            'channel' => 'global',
            'occurred_at' => now(),
        ]);

        $response = $this->get(route('specialties.chattiest', ['server' => $this->server->slug]));

        $response->assertOk();
        $row = collect($response->viewData('rows'))->firstWhere('player.id', $player->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->value); // solo la temporada activa

        $responseAll = $this->get(route('specialties.chattiest', ['server' => $this->server->slug, 'season' => 'all']));
        $rowAll = collect($responseAll->viewData('rows'))->firstWhere('player.id', $player->id);
        $this->assertSame(3, $rowAll->value); // las 2 temporadas
    }

    public function test_timeouts_excludes_old_season_events(): void
    {
        $oldSeason = Season::current();

        $oldMatch = $this->createConcludedMatch($oldSeason->id);
        MatchEvent::create([
            'server_id' => $this->server->id,
            'match_id' => $oldMatch->id,
            'event_type' => 'timeout_call',
            'side' => 'allies',
            'name' => 'OldTeam',
            'occurred_at' => now(),
        ]);

        $oldSeason->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $newMatch = $this->createConcludedMatch($newSeason->id);
        MatchEvent::create([
            'server_id' => $this->server->id,
            'match_id' => $newMatch->id,
            'event_type' => 'timeout_call',
            'side' => 'allies',
            'name' => 'NewTeam',
            'occurred_at' => now(),
        ]);
        MatchEvent::create([
            'server_id' => $this->server->id,
            'match_id' => $newMatch->id,
            'event_type' => 'timeout_call',
            'side' => 'axis',
            'name' => 'NewTeam',
            'occurred_at' => now(),
        ]);

        $response = $this->get(route('specialties.timeouts', ['server' => $this->server->slug]));

        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertSame(2, $rows->count()); // solo los 2 eventos de la temporada activa

        $responseAll = $this->get(route('specialties.timeouts', ['server' => $this->server->slug, 'season' => 'all']));
        $rowsAll = $responseAll->viewData('rows');
        $this->assertSame(3, $rowsAll->count()); // los 3 eventos de ambas temporadas
    }
}
