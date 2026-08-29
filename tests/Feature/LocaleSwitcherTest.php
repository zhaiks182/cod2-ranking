<?php

namespace Tests\Feature;

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
}
