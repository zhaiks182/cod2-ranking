<?php

namespace Tests\Feature\Admin;

use App\Models\Player;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get(route('admin.players.discord-accounts.index'))->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_without_the_players_module_gets_a_403(): void
    {
        $admin = User::factory()->create(['is_super_admin' => false, 'permissions' => []]);

        $this->actingAs($admin)->get(route('admin.players.discord-accounts.index'))->assertForbidden();
    }

    public function test_index_lists_linked_accounts(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->actingAs($admin)->get(route('admin.players.discord-accounts.index'))
            ->assertOk()
            ->assertSee('zhaiks')
            ->assertSee('Zhaiks');
    }

    public function test_unlink_clears_the_player_id_and_audits(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->actingAs($admin)->delete(route('admin.players.discord-accounts.unlink', $siteUser))->assertRedirect();

        $this->assertNull($siteUser->fresh()->player_id);
        $this->assertDatabaseHas('admin_actions', ['action' => 'site-users.unlink']);
    }

    public function test_update_role_sets_the_role_and_audits(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks']);

        $this->actingAs($admin)
            ->put(route('admin.players.discord-accounts.update-role', $siteUser), ['role' => 'Staff'])
            ->assertRedirect();

        $this->assertSame('Staff', $siteUser->fresh()->role);
        $this->assertDatabaseHas('admin_actions', ['action' => 'site-users.update-role']);
    }

    public function test_update_role_can_clear_the_role(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'role' => 'VIP']);

        $this->actingAs($admin)->put(route('admin.players.discord-accounts.update-role', $siteUser), ['role' => '']);

        $this->assertNull($siteUser->fresh()->role);
    }

    public function test_update_role_requires_the_players_module(): void
    {
        $admin = User::factory()->create(['is_super_admin' => false, 'permissions' => []]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks']);

        $this->actingAs($admin)
            ->put(route('admin.players.discord-accounts.update-role', $siteUser), ['role' => 'Staff'])
            ->assertForbidden();

        $this->assertNull($siteUser->fresh()->role);
    }
}
