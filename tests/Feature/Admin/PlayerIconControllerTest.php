<?php

namespace Tests\Feature\Admin;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Modulo de iconos personalizados por jugador (2026-08-28) -- generaliza el
 * burro hardcodeado de dtN.harek a cualquier jugador, subido desde acá.
 */
class PlayerIconControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function fakeImage(): UploadedFile
    {
        $image = imagecreatetruecolor(200, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 255, 0));

        $path = tempnam(sys_get_temp_dir(), 'icon').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'upload.png', 'image/png', null, true);
    }

    public function test_guests_are_redirected_to_login_for_index_store_and_destroy(): void
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'X', 'last_name_plain' => 'X']);

        $this->get(route('admin.players.icons.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.players.icons.store', $player), ['icon' => $this->fakeImage()])->assertRedirect(route('admin.login'));
        $this->delete(route('admin.players.icons.destroy', $player))->assertRedirect(route('admin.login'));
    }

    public function test_index_lists_players_and_shows_current_icon(): void
    {
        $admin = User::factory()->create();
        Player::create(['guid' => 1, 'last_name' => 'SomePlayer', 'last_name_plain' => 'SomePlayer']);

        $response = $this->actingAs($admin)->get(route('admin.players.icons.index'));

        $response->assertOk();
        $response->assertSee('SomePlayer');
    }

    public function test_store_uploads_and_resizes_the_icon_and_audits_it(): void
    {
        $admin = User::factory()->create();
        $player = Player::create(['guid' => 1127155189, 'last_name' => 'dtN.harek', 'last_name_plain' => 'dtN.harek']);

        $response = $this->actingAs($admin)->post(route('admin.players.icons.store', $player), [
            'icon' => $this->fakeImage(),
        ]);

        $response->assertRedirect();
        $player->refresh();

        $this->assertNotNull($player->icon_path);
        Storage::disk('public')->assertExists($player->icon_path);
        $this->assertDatabaseHas('admin_actions', ['action' => 'players.icon-upload']);
    }

    public function test_store_rejects_a_non_image_file(): void
    {
        $admin = User::factory()->create();
        $player = Player::create(['guid' => 1, 'last_name' => 'X', 'last_name_plain' => 'X']);

        $notAnImage = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin)->post(route('admin.players.icons.store', $player), [
            'icon' => $notAnImage,
        ]);

        $response->assertSessionHasErrors('icon');
        $this->assertNull($player->fresh()->icon_path);
    }

    public function test_destroy_removes_the_icon_and_audits_it(): void
    {
        $admin = User::factory()->create();
        $player = Player::create(['guid' => 1, 'last_name' => 'X', 'last_name_plain' => 'X']);
        $this->actingAs($admin)->post(route('admin.players.icons.store', $player), ['icon' => $this->fakeImage()]);
        $path = $player->fresh()->icon_path;

        $response = $this->actingAs($admin)->delete(route('admin.players.icons.destroy', $player));

        $response->assertRedirect();
        $this->assertNull($player->fresh()->icon_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('admin_actions', ['action' => 'players.icon-remove']);
    }
}
