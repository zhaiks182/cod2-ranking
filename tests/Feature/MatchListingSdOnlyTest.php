<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El pug es de Search & Destroy -- una partida real de otro gametype
 * (Headquarters, Deathmatch, etc.) no es un "resultado" en el sentido que
 * /partidas muestra, aunque haya tenido kills reales y un match_end valido.
 * Confirmado en vivo 2026-08-28 (match id=111, Burgundy HQ, 70 kills reales,
 * match_end real) apareciendo donde no debia. Confirmado con el dueño: ocultar
 * cualquier gametype que no sea sd en /partidas y /adm_cod2/partidas -- el
 * admin conserva el toggle "Mostrar incompletas" como via de escape para
 * encontrarla y borrarla a mano.
 */
class MatchListingSdOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        return Server::create([
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

    private function makeConcludedMatch(Server $server, string $gametype): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_burgundy_fix',
            'gametype' => $gametype,
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        \App\Models\MatchEvent::create([
            'server_id' => $server->id,
            'match_id' => $match->id,
            'event_type' => 'match_end',
            'occurred_at' => now(),
        ]);

        return $match;
    }

    public function test_public_listing_hides_non_sd_matches(): void
    {
        $server = $this->makeServer();
        $sd = $this->makeConcludedMatch($server, 'sd');
        $hq = $this->makeConcludedMatch($server, 'hq');

        $response = $this->get(route('matches.index', ['server' => $server->slug]));

        $response->assertOk();
        $response->assertSee('Search and Destroy');
        $response->assertDontSee('Headquarters');
    }

    public function test_admin_listing_hides_non_sd_matches_by_default_but_shows_with_toggle(): void
    {
        $admin = User::factory()->create();
        $server = $this->makeServer();
        $sd = $this->makeConcludedMatch($server, 'sd');
        $hq = $this->makeConcludedMatch($server, 'hq');

        $default = $this->actingAs($admin)->get(route('admin.matches.index', ['server' => $server->slug]));
        $default->assertOk();
        $default->assertSee('Search and Destroy');
        $default->assertDontSee('Headquarters');

        $withToggle = $this->actingAs($admin)->get(route('admin.matches.index', ['server' => $server->slug, 'incompletas' => 1]));
        $withToggle->assertOk();
        $withToggle->assertSee('Search and Destroy');
        $withToggle->assertSee('Headquarters');
    }
}
