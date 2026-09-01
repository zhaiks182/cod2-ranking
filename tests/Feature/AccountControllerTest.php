<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_a_non_http_url_scheme_is_rejected(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['steam_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('steam_url');

        $this->assertNull($siteUser->fresh()->steam_url);
    }

    public function test_status_endpoint_reports_unclaimed_while_a_claim_is_pending(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($siteUser, 'site')->getJson(route('account.status'))
            ->assertOk()
            ->assertJson(['claimed' => false, 'player_name' => null]);
    }

    public function test_status_endpoint_reports_claimed_once_the_code_was_confirmed(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')->getJson(route('account.status'))
            ->assertOk()
            ->assertJson(['claimed' => true, 'player_name' => 'Zhaiks']);
    }

    public function test_a_guest_cannot_hit_the_status_endpoint(): void
    {
        $this->get(route('account.status'))->assertRedirect(route('login'));
    }

    public function test_a_claimed_user_can_update_the_gaming_identity_fields(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')->post(route('account.update'), [
            'clan_tag' => 'Destino',
            'country' => 'ec',
            'language' => 'es',
            'preferred_role' => 'Asalto',
            'youtube_url' => 'https://youtube.com/@destino',
            'twitter_url' => 'https://x.com/destino',
            'website_url' => 'https://destino.gg',
        ])->assertRedirect();

        $siteUser->refresh();
        $this->assertSame('Destino', $siteUser->clan_tag);
        $this->assertSame('ec', $siteUser->country);
        $this->assertSame('es', $siteUser->language);
        $this->assertSame('Asalto', $siteUser->preferred_role);
        $this->assertSame('https://youtube.com/@destino', $siteUser->youtube_url);
        $this->assertSame('https://x.com/destino', $siteUser->twitter_url);
        $this->assertSame('https://destino.gg', $siteUser->website_url);
    }

    public function test_country_must_be_from_the_predefined_list(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['country' => 'zz'])
            ->assertSessionHasErrors('country');
    }

    public function test_language_must_be_a_supported_locale(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['language' => 'fr'])
            ->assertSessionHasErrors('language');
    }

    public function test_unchecking_show_on_ranking_hides_the_player_and_checking_it_shows_again(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);
        $this->assertTrue($siteUser->fresh()->show_on_ranking);

        // Un checkbox sin marcar no manda el campo en el POST -- tiene que
        // resolverse a false igual, no quedarse en true por default.
        $this->actingAs($siteUser, 'site')->post(route('account.update'), []);
        $this->assertFalse($siteUser->fresh()->show_on_ranking);

        $this->actingAs($siteUser, 'site')->post(route('account.update'), ['show_on_ranking' => '1']);
        $this->assertTrue($siteUser->fresh()->show_on_ranking);
    }

    public function test_a_claimed_user_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')->post(route('account.update'), [
            'avatar' => UploadedFile::fake()->image('foto.jpg', 300, 300),
        ])->assertRedirect();

        $siteUser->refresh();
        $this->assertNotNull($siteUser->avatar_path);
        Storage::disk('public')->assertExists($siteUser->avatar_path);
    }

    public function test_a_non_image_file_is_rejected_as_the_avatar(): void
    {
        Storage::fake('public');
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $this->actingAs($siteUser, 'site')
            ->post(route('account.update'), ['avatar' => UploadedFile::fake()->create('archivo.pdf', 100)])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($siteUser->fresh()->avatar_path);
    }
}
