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
}
