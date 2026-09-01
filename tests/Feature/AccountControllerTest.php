<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('account.show'))->assertRedirect(route('login'));
    }

    public function test_shows_the_pending_claim_code_when_there_is_one(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($siteUser, 'site')->get(route('account.show'))
            ->assertOk()
            ->assertSee('ABCDEFGH');
    }

    public function test_a_claimed_user_can_update_their_bio_and_socials(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')->post(route('account.update'), [
            'bio' => 'Jugador de CoD2 desde 2003.',
            'steam_url' => 'https://steamcommunity.com/id/zhaiks',
            'pc_cpu' => 'Ryzen 5600X',
        ])->assertRedirect();

        $siteUser->refresh();
        $this->assertSame('Jugador de CoD2 desde 2003.', $siteUser->bio);
        $this->assertSame('https://steamcommunity.com/id/zhaiks', $siteUser->steam_url);
        $this->assertSame('Ryzen 5600X', $siteUser->pc_cpu);
    }

    public function test_an_unclaimed_user_cannot_update_the_profile_fields(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['bio' => 'intento'])
            ->assertForbidden();

        $this->assertNull($siteUser->fresh()->bio);
    }

    public function test_the_bio_cannot_exceed_400_characters(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['bio' => str_repeat('x', 401)])
            ->assertSessionHasErrors('bio');
    }
}
