<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_user_with_specific_modules(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($super)->post(route('admin.users.store'), [
            'username' => 'moderador1',
            'password' => 'password123',
            'permissions' => ['bans', 'players'],
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $new = User::where('username', 'moderador1')->first();
        $this->assertNotNull($new);
        $this->assertFalse($new->is_super_admin);
        $this->assertSame(['bans', 'players'], $new->permissions);
        $this->assertDatabaseHas('admin_actions', ['action' => 'users.create']);
    }

    public function test_creates_a_super_admin_and_ignores_any_submitted_modules(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($super)->post(route('admin.users.store'), [
            'username' => 'segundosuper',
            'password' => 'password123',
            'is_super_admin' => '1',
            'permissions' => ['bans'],
        ]);

        $new = User::where('username', 'segundosuper')->first();
        $this->assertTrue($new->is_super_admin);
        $this->assertSame([], $new->permissions);
    }

    public function test_username_must_be_unique(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        User::factory()->create(['username' => 'yaexiste']);

        $response = $this->actingAs($super)->post(route('admin.users.store'), [
            'username' => 'yaexiste',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('username');
    }

    public function test_updates_modules_without_requiring_a_new_password(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create(['username' => 'mod1', 'permissions' => ['bans']]);
        $originalHash = $target->password;

        $this->actingAs($super)->put(route('admin.users.update', $target), [
            'username' => 'mod1',
            'permissions' => ['bans', 'demos'],
        ]);

        $target->refresh();
        $this->assertSame(['bans', 'demos'], $target->permissions);
        $this->assertSame($originalHash, $target->password);
    }

    public function test_cannot_demote_the_only_remaining_super_admin(): void
    {
        // La migracion de seed (2026_08_10_090006) ya crea un admin por defecto,
        // que la migracion de roles (2026_08_31_130000) tambien deja como
        // super-admin -- hay que sacarlo de en medio para que "el unico que
        // queda" sea realmente cierto en este test.
        User::where('is_super_admin', true)->delete();
        $onlySuper = User::factory()->create(['username' => 'onlysuper', 'is_super_admin' => true]);

        $response = $this->actingAs($onlySuper)->put(route('admin.users.update', $onlySuper), [
            'username' => $onlySuper->username,
            'is_super_admin' => '0',
            'permissions' => ['bans'],
        ]);

        $response->assertSessionHasErrors('is_super_admin');
        $this->assertTrue($onlySuper->fresh()->is_super_admin);
    }

    public function test_can_demote_a_super_admin_when_another_one_remains(): void
    {
        $superA = User::factory()->create(['username' => 'supera', 'is_super_admin' => true]);
        $superB = User::factory()->create(['username' => 'superb', 'is_super_admin' => true]);

        $response = $this->actingAs($superA)->put(route('admin.users.update', $superB), [
            'username' => $superB->username,
            'is_super_admin' => '0',
            'permissions' => ['demos'],
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertFalse($superB->fresh()->is_super_admin);
    }

    public function test_cannot_delete_your_own_account(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($super)->delete(route('admin.users.destroy', $super));

        $response->assertSessionHasErrors('user');
        $this->assertNotNull($super->fresh());
    }

    public function test_deletes_a_regular_user_and_records_it_in_the_audit_log(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create(['username' => 'borrame', 'permissions' => ['demos']]);

        $response = $this->actingAs($super)->delete(route('admin.users.destroy', $target));

        $response->assertRedirect();
        $this->assertNull(User::find($target->id));
        $this->assertDatabaseHas('admin_actions', ['action' => 'users.destroy']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('admin.login'));
    }
}
