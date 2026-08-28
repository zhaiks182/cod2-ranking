<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerMergeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.players.merge.index'))->assertRedirect(route('admin.login'));
    }

    public function test_search_finds_players_by_current_name_or_alias(): void
    {
        $admin = User::factory()->create();

        $reload = Player::create(['guid' => 60, 'last_name' => 'MOKOS RELOAD', 'last_name_plain' => 'MOKOS RELOAD', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $reload->aliases()->create(['name' => 'MOKOS', 'name_plain' => 'MOKOS', 'last_seen_at' => now()]);

        Player::create(['guid' => 999, 'last_name' => 'Unrelated', 'last_name_plain' => 'Unrelated', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $response = $this->actingAs($admin)->get(route('admin.players.merge.index', ['q' => 'MOKOS']));

        $response->assertOk();
        $response->assertSee('MOKOS RELOAD');
        $response->assertDontSee('Unrelated');
    }

    public function test_merging_moves_data_and_logs_an_admin_action(): void
    {
        $admin = User::factory()->create();

        $target = Player::create(['guid' => 60, 'last_name' => 'MOKOS RELOAD', 'last_name_plain' => 'MOKOS RELOAD', 'kills_total' => 115, 'deaths_total' => 298, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $source = Player::create(['guid' => 50, 'last_name' => 'MOKOS', 'last_name_plain' => 'MOKOS', 'kills_total' => 11, 'deaths_total' => 59, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $response = $this->actingAs($admin)->post(route('admin.players.merge.store'), [
            'target_id' => $target->id,
            'source_ids' => [$target->id, $source->id],
        ]);

        $response->assertRedirect(route('players.show', $target->guid));

        $target->refresh();
        $this->assertSame(126, $target->kills_total);
        $this->assertDatabaseMissing('players', ['id' => $source->id]);
        $this->assertDatabaseHas('admin_actions', ['action' => 'players.merge']);
    }

    public function test_target_must_be_among_the_selected_source_ids(): void
    {
        $admin = User::factory()->create();

        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $c = Player::create(['guid' => 3, 'last_name' => 'C', 'last_name_plain' => 'C', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $response = $this->actingAs($admin)->post(route('admin.players.merge.store'), [
            'target_id' => $c->id,
            'source_ids' => [$a->id, $b->id],
        ]);

        $response->assertSessionHasErrors('target_id');
        $this->assertDatabaseHas('players', ['id' => $a->id]);
        $this->assertDatabaseHas('players', ['id' => $b->id]);
    }
}
