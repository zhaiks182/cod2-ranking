<?php

namespace Tests\Feature\Admin;

use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Player;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClanControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeClan(): Clan
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'F', 'last_name_plain' => 'F']);
        $founder = SiteUser::create(['discord_id' => '1', 'discord_username' => 'f', 'player_id' => $player->id]);
        $clan = Clan::create(['name' => 'Destino', 'tag' => 'DEST', 'founder_site_user_id' => $founder->id]);
        ClanMember::create(['clan_id' => $clan->id, 'site_user_id' => $founder->id, 'role' => 'founder', 'joined_at' => now()]);

        return $clan;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $clan = $this->makeClan();

        $this->get(route('admin.clans.index'))->assertRedirect(route('admin.login'));
        $this->delete(route('admin.clans.destroy', $clan))->assertRedirect(route('admin.login'));
    }

    public function test_index_lists_clans_with_member_counts(): void
    {
        $this->makeClan();
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.clans.index'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('clans')->first()->members_count);
    }

    public function test_destroy_force_disbands_a_clan_and_audits_it(): void
    {
        $clan = $this->makeClan();
        $admin = User::factory()->create();

        $this->actingAs($admin)->delete(route('admin.clans.destroy', $clan))->assertRedirect();

        $this->assertDatabaseCount('clans', 0);
        $this->assertDatabaseHas('admin_actions', ['action' => 'clans.destroy']);
    }
}
