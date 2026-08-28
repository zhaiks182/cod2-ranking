<?php

namespace Tests\Feature\Support;

use App\Models\Player;
use App\Support\PlayerIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Modulo de iconos personalizados por jugador (2026-08-28, /adm_cod2/jugadores/iconos)
 * -- generaliza el burro hardcodeado de dtN.harek. "El icono siempre debe
 * ajustarse" (pedido del dueño): cualquier imagen subida se re-escala server-side
 * con GD antes de guardarse, nunca se confia en el archivo original tal cual.
 */
class PlayerIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function fakeImage(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));

        $path = tempnam(sys_get_temp_dir(), 'icon').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'upload.png', 'image/png', null, true);
    }

    public function test_store_resizes_a_large_image_down_to_the_max_dimension(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        PlayerIcon::store($player, $this->fakeImage(600, 400));
        $player->refresh();

        $this->assertNotNull($player->icon_path);
        Storage::disk('public')->assertExists($player->icon_path);

        $size = getimagesize(Storage::disk('public')->path($player->icon_path));
        $this->assertLessThanOrEqual(128, $size[0]);
        $this->assertLessThanOrEqual(128, $size[1]);
        // Aspect ratio (3:2) must survive the resize, same as burro.png (294x512)
        // being shown with a fixed width and auto height instead of a forced square.
        // A small tolerance accounts for integer pixel rounding on the target size.
        $this->assertEqualsWithDelta(600 / 400, $size[0] / $size[1], 0.02);
    }

    public function test_store_never_upscales_a_small_image(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        PlayerIcon::store($player, $this->fakeImage(40, 40));
        $player->refresh();

        $size = getimagesize(Storage::disk('public')->path($player->icon_path));
        $this->assertSame(40, $size[0]);
        $this->assertSame(40, $size[1]);
    }

    public function test_store_replaces_the_previous_icon_at_the_same_path(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        PlayerIcon::store($player, $this->fakeImage(100, 100));
        $player->refresh();
        $firstPath = $player->icon_path;
        $firstContents = Storage::disk('public')->get($firstPath);

        PlayerIcon::store($player, $this->fakeImage(50, 50));
        $player->refresh();

        $this->assertSame($firstPath, $player->icon_path, 'Same player must always resolve to the same icon path.');
        $this->assertNotSame($firstContents, Storage::disk('public')->get($player->icon_path));
    }

    public function test_destroy_removes_the_file_and_clears_the_column(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        PlayerIcon::store($player, $this->fakeImage(100, 100));
        $player->refresh();
        $path = $player->icon_path;

        PlayerIcon::destroy($player);
        $player->refresh();

        $this->assertNull($player->icon_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_icon_url_accessor_is_null_without_an_icon(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $this->assertNull($player->icon_url);
    }
}
