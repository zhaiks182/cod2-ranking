<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
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
            'preferred_role' => 'weapon_mp44',
            'youtube_url' => 'https://youtube.com/@destino',
            'twitter_url' => 'https://x.com/destino',
            'website_url' => 'https://destino.gg',
        ])->assertRedirect();

        $siteUser->refresh();
        $this->assertSame('Destino', $siteUser->clan_tag);
        $this->assertSame('ec', $siteUser->country);
        $this->assertSame('es', $siteUser->language);
        $this->assertSame('weapon_mp44', $siteUser->preferred_role);
        $this->assertSame('https://youtube.com/@destino', $siteUser->youtube_url);
        $this->assertSame('https://x.com/destino', $siteUser->twitter_url);
        $this->assertSame('https://destino.gg', $siteUser->website_url);
    }

    /**
     * "Rol preferido" (2026-09-01) se elige entre las armas con las que el
     * jugador REALMENTE tiene bajas -- no texto libre inventado. Confirma
     * que el listado que arma AccountController::show() viene de kills
     * reales, ordenado por la mas usada primero.
     */
    public function test_the_account_page_lists_the_players_real_weapons_most_used_first(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'Victim', 'last_name_plain' => 'Victim']);
        $match = GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
        $round = Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        $makeKill = fn (string $weapon) => Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $player->id, 'attacker_guid' => $player->guid, 'attacker_name' => $player->last_name, 'attacker_team' => 'allies',
            'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
            'weapon' => $weapon, 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'head',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);
        $makeKill('weapon_kar98k'); // 1 baja
        $makeKill('weapon_mp44'); // 2 bajas -- la mas usada
        $makeKill('weapon_mp44');

        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $response = $this->actingAs($siteUser, 'site')->get(route('account.show'));

        $response->assertOk();
        $codes = collect($response->viewData('usedWeapons'))->pluck('code');
        $this->assertSame(['weapon_mp44', 'weapon_kar98k'], $codes->all());
    }

    public function test_the_account_page_shows_no_weapons_without_any_real_kills(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id]);

        $response = $this->actingAs($siteUser, 'site')->get(route('account.show'));

        $response->assertOk();
        $this->assertTrue(collect($response->viewData('usedWeapons'))->isEmpty());
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
