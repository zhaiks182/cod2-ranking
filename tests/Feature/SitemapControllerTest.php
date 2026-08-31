<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * sitemap.xml (2026-08-31, a pedido del dueño) -- lista las paginas publicas
 * estaticas mas cada partida/jugador real, para que Google los indexe sin
 * tener que rastrear todo el sitio a mano.
 */
class SitemapControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private Server $server;

    private function server(): Server
    {
        return $this->server ??= Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function realMatch(string $gametype = 'sd', bool $backfilled = false): GameMatch
    {
        $server = $this->server();
        $winner = Player::create(['guid' => 999, 'last_name' => 'W', 'last_name_plain' => 'W']);

        $match = GameMatch::create([
            'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => $gametype,
            'started_at' => now()->subMinutes(20), 'ended_at' => now(),
        ]);
        if ($backfilled) {
            $match->forceFill(['is_backfilled' => true])->save();
        }

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => $gametype,
                'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [$winner->guid],
            ]);
        }

        return $match;
    }

    public function test_returns_xml_with_the_correct_content_type(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false);
        $response->assertSee('<urlset', false);
    }

    public function test_includes_the_main_static_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertSee(route('dashboard'), false);
        $response->assertSee(route('leaderboard'), false);
        $response->assertSee(route('matches.index'), false);
        $response->assertSee(route('specialties.weapons'), false);
        $response->assertSee(route('faq'), false);
    }

    public function test_never_includes_admin_or_private_routes(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertDontSee('adm_cod2', false);
        $response->assertDontSee('/servidores/', false);
        $response->assertDontSee(route('locale.switch', 'es'), false);
    }

    public function test_includes_a_real_concluded_sd_match(): void
    {
        $match = $this->realMatch();

        $response = $this->get('/sitemap.xml');

        $response->assertSee(route('matches.show', $match), false);
    }

    public function test_excludes_a_backfilled_match(): void
    {
        $match = $this->realMatch(backfilled: true);

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee(route('matches.show', $match), false);
    }

    public function test_excludes_a_non_sd_match(): void
    {
        $match = $this->realMatch(gametype: 'dm');

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee(route('matches.show', $match), false);
    }

    public function test_excludes_an_abandoned_match_without_a_real_conclusion(): void
    {
        $server = $this->server();
        $match = GameMatch::create([
            'server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now()->subMinutes(20), 'ended_at' => now(),
        ]);
        Round::create([
            'server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [111],
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee(route('matches.show', $match), false);
    }

    public function test_includes_a_player_with_real_activity(): void
    {
        $player = Player::create(['guid' => 555, 'last_name' => 'Real', 'last_name_plain' => 'Real', 'kills_total' => 10]);

        $response = $this->get('/sitemap.xml');

        $response->assertSee(route('players.show', $player), false);
    }

    public function test_excludes_a_zero_activity_phantom_player(): void
    {
        $phantom = Player::create(['guid' => 556, 'last_name' => 'Phantom', 'last_name_plain' => 'Phantom', 'kills_total' => 0, 'deaths_total' => 0]);

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee(route('players.show', $phantom), false);
    }

    public function test_response_is_cached(): void
    {
        $this->get('/sitemap.xml');

        $this->assertTrue(Cache::has('sitemap.xml'));
    }
}
