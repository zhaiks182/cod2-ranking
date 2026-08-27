<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_the_active_season_and_past_seasons(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.seasons.index'));

        $response->assertOk();
        $response->assertSee('Temporada 1');
    }

    public function test_store_closes_the_current_season_and_opens_a_new_one(): void
    {
        $admin = User::factory()->create();
        $oldSeason = Season::current();

        $this->actingAs($admin)
            ->post(route('admin.seasons.store'), ['name' => 'Temporada 2'])
            ->assertRedirect();

        $oldSeason->refresh();
        $this->assertNotNull($oldSeason->ended_at);

        $newSeason = Season::current();
        $this->assertSame('Temporada 2', $newSeason->name);
        $this->assertNotSame($oldSeason->id, $newSeason->id);
    }

    public function test_store_requires_a_name(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.seasons.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        // La temporada activa no debe haber cambiado.
        $this->assertSame('Temporada 1', Season::current()->name);
    }

    public function test_store_records_an_admin_action(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.seasons.store'), ['name' => 'Temporada 2']);

        $this->assertTrue(AdminAction::where('action', 'seasons.close')->exists());
    }

    public function test_reactivate_closes_the_active_season_and_reopens_the_chosen_one(): void
    {
        $admin = User::factory()->create();
        $season1 = Season::current();
        $startedAt = $season1->started_at;

        $this->actingAs($admin)->post(route('admin.seasons.store'), ['name' => 'Temporada 2']);
        $season2 = Season::current();

        $this->actingAs($admin)
            ->post(route('admin.seasons.reactivate', $season1))
            ->assertRedirect();

        $season1->refresh();
        $season2->refresh();

        $this->assertNull($season1->ended_at);
        $this->assertNotNull($season2->ended_at);
        $this->assertSame($season1->id, Season::current()->id);
        // started_at original de la temporada reactivada no se toca.
        $this->assertTrue($season1->started_at->equalTo($startedAt));
    }

    public function test_reactivate_rejects_the_currently_active_season(): void
    {
        $admin = User::factory()->create();
        $active = Season::current();

        $this->actingAs($admin)
            ->post(route('admin.seasons.reactivate', $active))
            ->assertSessionHasErrors();

        $this->assertNull($active->refresh()->ended_at);
    }

    public function test_reactivate_records_an_admin_action(): void
    {
        $admin = User::factory()->create();
        $season1 = Season::current();
        $this->actingAs($admin)->post(route('admin.seasons.store'), ['name' => 'Temporada 2']);

        $this->actingAs($admin)->post(route('admin.seasons.reactivate', $season1));

        $this->assertTrue(AdminAction::where('action', 'seasons.reactivate')->exists());
    }
}
