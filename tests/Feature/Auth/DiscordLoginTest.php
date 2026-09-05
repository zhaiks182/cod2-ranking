<?php

namespace Tests\Feature\Auth;

use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class DiscordLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $raw  Payload crudo de /users/@me -- Socialite
     *      no mapea global_name/locale/verified a su objeto User, el controller los
     *      lee de getRaw() (ver SiteAuthController::callback).
     */
    private function fakeDiscordUser(string $id = '111111111111111111', string $username = 'zhaiks', array $raw = [], ?string $email = null): SocialiteUser
    {
        return (new SocialiteUser())->setRaw($raw)->map([
            'id' => $id,
            'nickname' => $username,
            'name' => $username,
            'email' => $email,
            'avatar' => "https://cdn.discordapp.com/avatars/{$id}/abc.png",
        ]);
    }

    /**
     * El controller llama setHttpClient() antes de user() (timeout corto,
     * 2026-09-01) -- el mock tiene que conocer ese metodo intermedio, si no
     * Mockery revienta con "method does not exist" apenas se llama.
     */
    private function mockDiscordDriver(): \Mockery\MockInterface
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('setHttpClient')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

        return $provider;
    }

    public function test_callback_creates_a_new_site_user_and_logs_them_in(): void
    {
        $this->mockDiscordDriver()->shouldReceive('user')->andReturn($this->fakeDiscordUser());

        $response = $this->get('/auth/discord/callback');

        $siteUser = SiteUser::where('discord_id', '111111111111111111')->first();
        $this->assertNotNull($siteUser);
        $this->assertSame('zhaiks', $siteUser->discord_username);
        $this->assertAuthenticated('site');
        $response->assertRedirect(route('account.show'));
    }

    public function test_callback_updates_the_username_and_avatar_on_a_returning_user_without_duplicating_the_row(): void
    {
        SiteUser::create(['discord_id' => '111111111111111111', 'discord_username' => 'nombreviejo']);

        $this->mockDiscordDriver()->shouldReceive('user')->andReturn($this->fakeDiscordUser(username: 'nombrenuevo'));

        $this->get('/auth/discord/callback');

        $this->assertSame(1, SiteUser::where('discord_id', '111111111111111111')->count());
        $this->assertSame('nombrenuevo', SiteUser::first()->discord_username);
    }

    public function test_callback_stores_the_discord_fields_that_already_came_in_the_payload(): void
    {
        $this->mockDiscordDriver()->shouldReceive('user')->andReturn($this->fakeDiscordUser(
            raw: ['global_name' => 'Zhaiks', 'locale' => 'es-ES', 'verified' => true],
            email: 'zhaiks@example.com',
        ));

        $this->get('/auth/discord/callback');

        $siteUser = SiteUser::first();
        $this->assertSame('Zhaiks', $siteUser->discord_global_name);
        $this->assertSame('zhaiks@example.com', $siteUser->discord_email);
        $this->assertTrue($siteUser->discord_email_verified);
        $this->assertSame('es-ES', $siteUser->discord_locale);
    }

    public function test_callback_fills_the_profile_language_from_the_discord_locale(): void
    {
        $this->mockDiscordDriver()->shouldReceive('user')->andReturn($this->fakeDiscordUser(
            raw: ['locale' => 'en-US'],
        ));

        $this->get('/auth/discord/callback');

        $this->assertSame('en', SiteUser::first()->language);
    }

    /**
     * `language` es editable desde /mi-cuenta, asi que autocompletarlo en CADA
     * login le revertiria la eleccion a cualquiera que use Discord en un idioma
     * distinto del que quiere ver el sitio -- solo se completa si esta vacio.
     */
    public function test_callback_never_overwrites_a_language_the_player_already_chose(): void
    {
        SiteUser::create([
            'discord_id' => '111111111111111111',
            'discord_username' => 'zhaiks',
            'language' => 'en',
        ]);

        $this->mockDiscordDriver()->shouldReceive('user')->andReturn($this->fakeDiscordUser(
            raw: ['locale' => 'es-ES'],
        ));

        $this->get('/auth/discord/callback');

        $this->assertSame('en', SiteUser::first()->language);
    }

    /**
     * Discord tiene decenas de locales; el sitio solo soporta es/en
     * (SetLocale::SUPPORTED). Uno que no mapea no debe adivinar ni guardar basura.
     */
    public function test_an_unsupported_discord_locale_leaves_the_profile_language_empty(): void
    {
        $this->mockDiscordDriver()->shouldReceive('user')->andReturn($this->fakeDiscordUser(
            raw: ['locale' => 'fr'],
        ));

        $this->get('/auth/discord/callback');

        $siteUser = SiteUser::first();
        $this->assertNull($siteUser->language);
        $this->assertSame('fr', $siteUser->discord_locale);
    }

    public function test_guests_hitting_a_site_protected_route_are_sent_to_the_public_login_not_the_admin_one(): void
    {
        $response = $this->get('/mi-cuenta');

        $response->assertRedirect(route('login'));
    }

    public function test_logout_clears_the_site_session(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'x']);
        $this->actingAs($siteUser, 'site');

        $this->post('/logout');

        $this->assertGuest('site');
    }

    /**
     * Bug real (2026-09-01): un corte de red momentaneo entre el VPS y Discord
     * ("cURL error 52: Empty reply from server") dejaba la excepcion sin
     * atrapar -- el usuario veia un error crudo (o la request colgada mucho
     * tiempo, sin el timeout que se agrego en el mismo fix). Ahora cualquier
     * falla de Socialite redirige con un mensaje claro, sin crear ni loguear
     * a nadie.
     */
    public function test_a_failed_discord_exchange_redirects_with_a_friendly_error_and_does_not_log_anyone_in(): void
    {
        $this->mockDiscordDriver()->shouldReceive('user')->andThrow(new \Exception('cURL error 52: Empty reply from server'));

        $response = $this->get('/auth/discord/callback');

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
        $this->assertGuest('site');
        $this->assertSame(0, SiteUser::count());
    }

    /**
     * "invalid_grant" (codigo de Discord ya usado, ej. el navegador reintenta
     * el callback) es el otro caso real visto en produccion -- mismo manejo
     * que cualquier otra falla de Socialite.
     */
    public function test_an_invalid_grant_error_also_redirects_with_a_friendly_error(): void
    {
        $this->mockDiscordDriver()->shouldReceive('user')->andThrow(new \Exception('Client error: 400 Bad Request: invalid_grant'));

        $response = $this->get('/auth/discord/callback');

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
        $this->assertGuest('site');
    }
}
