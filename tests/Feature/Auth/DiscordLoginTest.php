<?php

namespace Tests\Feature\Auth;

use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class DiscordLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDiscordUser(string $id = '111111111111111111', string $username = 'zhaiks'): SocialiteUser
    {
        return (new SocialiteUser())->setRaw([])->map([
            'id' => $id,
            'nickname' => $username,
            'name' => $username,
            'avatar' => "https://cdn.discordapp.com/avatars/{$id}/abc.png",
        ]);
    }

    public function test_callback_creates_a_new_site_user_and_logs_them_in(): void
    {
        Socialite::shouldReceive('driver->user')->andReturn($this->fakeDiscordUser());

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

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeDiscordUser(username: 'nombrenuevo'));

        $this->get('/auth/discord/callback');

        $this->assertSame(1, SiteUser::where('discord_id', '111111111111111111')->count());
        $this->assertSame('nombrenuevo', SiteUser::first()->discord_username);
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
}
