<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerProfileClaimDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_clan_tag_no_longer_appears_next_to_the_name_on_the_stats_page(): void
    {
        // 2026-09-01, a pedido del dueño ("el clan no debe aparecer al lado
        // del nombre") -- se movio a su propia linea en players.profile.
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $player->id, 'clan_tag' => 'Destino']);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee('[Destino]');
    }

    public function test_shows_a_link_to_the_full_profile_page_when_claimed(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee(__('Ver perfil completo'))
            ->assertSee(route('players.profile', $player), false);
    }

    public function test_hides_the_profile_link_when_not_claimed(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee(__('Ver perfil completo'));
    }

    public function test_bio_no_longer_appears_inline_on_the_stats_page(): void
    {
        // 2026-09-01, a pedido del dueño ("no me gusta mezclado con las stats")
        // -- bio/redes/specs se movieron a su propia pagina, players.profile.
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id,
            'bio' => 'Jugador desde 2003.',
        ]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertDontSee('Jugador desde 2003.');
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
