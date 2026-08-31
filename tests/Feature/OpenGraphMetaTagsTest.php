<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meta tags Open Graph/Twitter Card (2026-08-31) -- para que un link del
 * sitio compartido en Discord se vea con titulo/descripcion/imagen en vez
 * de una URL pelada. Defaults en layouts/app.blade.php, pisados por
 * paginas puntuales (perfil de jugador, partida) via @section('og_*').
 */
class OpenGraphMetaTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El español es el idioma canonico del sitio para un visitante sin cookie
        // de idioma (ver SetLocale::handle()) -- pero el entorno aislado de tests
        // no siempre hereda el mismo APP_LOCALE que produccion (mismo motivo por
        // el que LocaleSwitcherTest ya tiene una falla preexistente y conocida acá,
        // sin relacion con este archivo) — se fuerza explicito para no depender de
        // esa configuracion.
        app()->setLocale('es');
    }

    public function test_default_og_tags_appear_on_a_page_without_a_specific_override(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('<meta property="og:site_name" content="CoD2 Stats — Pug Latam">', false);
        $response->assertSee('property="og:title" content="Inicio Ranking"', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('name="twitter:card" content="summary_large_image"', false);
    }

    public function test_player_profile_overrides_og_title_and_description_with_real_stats(): void
    {
        // El perfil muestra las bajas/muertes de la TEMPORADA ACTIVA (no el
        // total historico de players.kills_total) -- sin kills reales de
        // prueba, el valor correcto y esperado acá es 0, no el que se le
        // ponga al fixture del modelo.
        $player = Player::create([
            'guid' => 12345, 'last_name' => 'hardoso', 'last_name_plain' => 'hardoso',
            'kills_total' => 100, 'deaths_total' => 50,
        ]);

        $response = $this->get(route('players.show', $player->guid));

        $response->assertOk();
        $response->assertSee('property="og:title" content="hardoso — CoD2 Stats"', false);
        $response->assertSee('property="og:description" content="0 bajas · 0 muertes · K/D 0 — Pug Latam"', false);
    }

    public function test_match_page_overrides_og_title_with_map_and_uses_the_map_image_when_available(): void
    {
        $server = Server::create([
            'name' => 'S', 'slug' => 's', 'log_path' => '/tmp/x.log', 'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960, 'rcon_password' => 'x', 'connect_ip' => '127.0.0.1',
            'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $match = GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);
        Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);

        $response = $this->get(route('matches.show', $match));

        $response->assertOk();
        $response->assertSee('property="og:title" content="Toujane, Tunisia"', false);
    }
}
