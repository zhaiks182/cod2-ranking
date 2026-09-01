<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerProfileClaimDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_bio_and_socials_when_the_player_is_claimed(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id,
            'bio' => 'Jugador desde 2003.', 'steam_url' => 'https://steamcommunity.com/id/zhaiks',
        ]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Jugador desde 2003.')
            ->assertSee('https://steamcommunity.com/id/zhaiks', false);
    }

    public function test_hides_the_empty_profile_card_when_the_player_has_no_bio_socials_or_specs_yet(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee('id="player-profile-card"', false);
    }

    public function test_shows_a_clickable_discord_badge_next_to_the_name_when_claimed(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '221538919068467200', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('https://discord.com/users/221538919068467200', false)
            ->assertSee('Discord: zhaiks');
    }

    public function test_shows_the_community_role_badge_when_set(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id, 'role' => 'Staff']);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Staff');
    }

    public function test_hides_the_role_badge_when_not_set(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->get(route('players.show', $player))->assertOk();
        $this->assertNull($player->siteUser?->role);
    }

    public function test_shows_the_claim_button_to_a_logged_in_visitor_without_a_claim_of_their_own(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->get(route('players.show', $player))
            ->assertOk()
            ->assertSee(__('¿Sos vos? Reclamá este perfil'));
    }

    public function test_hides_the_claim_button_from_an_anonymous_visitor(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee(__('¿Sos vos? Reclamá este perfil'));
    }

    public function test_hides_the_claim_button_when_the_player_is_already_claimed_by_someone_else(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => 'owner', 'discord_username' => 'dueño', 'player_id' => $player->id]);
        $viewer = SiteUser::create(['discord_id' => 'viewer', 'discord_username' => 'visitante']);

        $this->actingAs($viewer, 'site')->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee(__('¿Sos vos? Reclamá este perfil'));
    }
}
