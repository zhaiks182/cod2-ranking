<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selector ES/EN del sitio publico (2026-08-29, referencia hostgamer.net/es).
 * Español es el idioma "fuente" (el texto vive en __() tal cual, sin claves
 * abstractas) -- por default (sin cookie) debe verse igual que siempre.
 */
class LocaleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_visitor_sees_spanish(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Inicio');
        $response->assertDontSee('Home');
    }

    public function test_switching_to_english_sets_a_long_lived_cookie_and_redirects_back(): void
    {
        $response = $this->from('/ranking')->get(route('locale.switch', 'en'));

        $response->assertRedirect('/ranking');
        $response->assertCookie('locale', 'en');
    }

    public function test_visitor_with_english_cookie_sees_translated_nav(): void
    {
        $response = $this->withCookie('locale', 'en')->get('/');

        $response->assertOk();
        $response->assertSee('Home');
        $response->assertDontSee('Inicio');
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $response = $this->get('/idioma/fr');

        $response->assertNotFound();
    }

    public function test_unknown_cookie_value_is_ignored_and_falls_back_to_spanish(): void
    {
        $response = $this->withCookie('locale', 'fr')->get('/');

        $response->assertOk();
        $response->assertSee('Inicio');
    }

    /**
     * partials/team-balance.blade.php se comparte entre /equipos (publico, ya
     * traducido) y admin/console.blade.php -- sin el corte en SetLocale, la
     * cookie "en" de una visita anterior al sitio publico se filtraria a ese
     * partial dentro del panel admin, que debe quedar siempre en español.
     */
    public function test_admin_panel_ignores_the_locale_cookie(): void
    {
        $admin = User::factory()->create();
        $server = Server::create([
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

        $response = $this->withCookie('locale', 'en')
            ->actingAs($admin)
            ->get(route('admin.console.show', $server));

        $response->assertOk();
        $response->assertSee('Balanceo sugerido de equipos');
        $response->assertDontSee('Suggested team balance');
    }
}
