<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sistema de roles del panel admin (2026-08-31, a pedido del dueño) --
 * User::hasModule(), el middleware `module:<clave>` que gatea cada grupo de
 * rutas, y el middleware `super-admin` (solo para /adm_cod2/usuarios, nunca
 * un modulo otorgable -- ver User::MODULES).
 */
class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_has_every_module_regardless_of_permissions(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'permissions' => []]);

        $this->assertTrue($admin->hasModule('servers'));
        $this->assertTrue($admin->hasModule('bans'));
        $this->assertTrue($admin->hasModule('anything-not-a-real-module'));
    }

    public function test_regular_user_only_has_the_modules_assigned(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['matches', 'demos']]);

        $this->assertTrue($user->hasModule('matches'));
        $this->assertTrue($user->hasModule('demos'));
        $this->assertFalse($user->hasModule('servers'));
        $this->assertFalse($user->hasModule('bans'));
    }

    public function test_user_with_no_permissions_assigned_has_no_modules(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => null]);

        $this->assertFalse($user->hasModule('matches'));
    }

    public function test_middleware_blocks_a_route_the_user_has_no_module_for(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['demos']]);

        $response = $this->actingAs($user)->get(route('admin.bans.index'));

        $response->assertForbidden();
    }

    public function test_middleware_allows_a_route_the_user_has_the_module_for(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['bans']]);

        $response = $this->actingAs($user)->get(route('admin.bans.index'));

        $response->assertOk();
    }

    public function test_super_admin_can_reach_every_module_route(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'permissions' => []]);

        $this->actingAs($admin)->get(route('admin.bans.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.seasons.index'))->assertOk();
    }

    public function test_own_password_page_is_reachable_regardless_of_modules(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => []]);

        $response = $this->actingAs($user)->get(route('admin.password.edit'));

        $response->assertOk();
    }

    public function test_users_management_requires_super_admin_even_with_every_other_module(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => array_keys(User::MODULES)]);

        $response = $this->actingAs($user)->get(route('admin.users.index'));

        $response->assertForbidden();
    }

    public function test_super_admin_can_reach_user_management(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
    }
}
