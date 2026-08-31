<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtros de /adm_cod2/auditoria (2026-08-31, a pedido del dueño) -- antes era
 * una lista plana sin forma de acotar por admin, tipo de accion, o fecha.
 */
class AuditControllerFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_admin(): void
    {
        $viewer = User::factory()->create();
        $adminA = User::factory()->create(['username' => 'admin_a']);
        $adminB = User::factory()->create(['username' => 'admin_b']);
        AdminAction::create(['user_id' => $adminA->id, 'action' => 'players.destroy', 'description' => 'De A']);
        AdminAction::create(['user_id' => $adminB->id, 'action' => 'players.destroy', 'description' => 'De B']);

        $response = $this->actingAs($viewer)->get(route('admin.audit.index', ['admin' => $adminA->id]));

        $response->assertOk();
        $response->assertSee('De A');
        $response->assertDontSee('De B');
    }

    public function test_filters_by_action_substring(): void
    {
        $viewer = User::factory()->create();
        AdminAction::create(['user_id' => $viewer->id, 'action' => 'players.destroy', 'description' => 'Borro jugador']);
        AdminAction::create(['user_id' => $viewer->id, 'action' => 'seasons.close', 'description' => 'Cerro temporada']);

        $response = $this->actingAs($viewer)->get(route('admin.audit.index', ['action' => 'players.']));

        $response->assertOk();
        $response->assertSee('Borro jugador');
        $response->assertDontSee('Cerro temporada');
    }

    public function test_filters_by_date_range(): void
    {
        $viewer = User::factory()->create();
        $old = AdminAction::create(['user_id' => $viewer->id, 'action' => 'x', 'description' => 'Vieja']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        AdminAction::create(['user_id' => $viewer->id, 'action' => 'x', 'description' => 'Reciente']);

        $response = $this->actingAs($viewer)->get(route('admin.audit.index', ['from' => now()->subDay()->toDateString()]));

        $response->assertOk();
        $response->assertSee('Reciente');
        $response->assertDontSee('Vieja');
    }

    public function test_without_filters_shows_everything(): void
    {
        $viewer = User::factory()->create();
        AdminAction::create(['user_id' => $viewer->id, 'action' => 'a', 'description' => 'Uno']);
        AdminAction::create(['user_id' => $viewer->id, 'action' => 'b', 'description' => 'Dos']);

        $response = $this->actingAs($viewer)->get(route('admin.audit.index'));

        $response->assertOk();
        $response->assertSee('Uno');
        $response->assertSee('Dos');
    }
}
