<?php

namespace Tests\Feature\Support;

use App\Models\SiteUser;
use App\Support\SiteUserAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto de perfil autodeclarada (2026-09-01, modulo de perfil gaming) -- mismo
 * patron de resize server-side con GD que PlayerIcon, ver ese test para el
 * porque de cada caso.
 */
class SiteUserAvatarTest extends TestCase
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
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 128, 255));

        $path = tempnam(sys_get_temp_dir(), 'avatar').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'upload.png', 'image/png', null, true);
    }

    public function test_store_resizes_a_large_image_down_to_the_max_dimension(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        SiteUserAvatar::store($siteUser, $this->fakeImage(1200, 800));
        $siteUser->refresh();

        $this->assertNotNull($siteUser->avatar_path);
        Storage::disk('public')->assertExists($siteUser->avatar_path);

        $size = getimagesize(Storage::disk('public')->path($siteUser->avatar_path));
        $this->assertLessThanOrEqual(256, $size[0]);
        $this->assertLessThanOrEqual(256, $size[1]);
        $this->assertEqualsWithDelta(1200 / 800, $size[0] / $size[1], 0.02);
    }

    public function test_store_never_upscales_a_small_image(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        SiteUserAvatar::store($siteUser, $this->fakeImage(60, 60));
        $siteUser->refresh();

        $size = getimagesize(Storage::disk('public')->path($siteUser->avatar_path));
        $this->assertSame(60, $size[0]);
        $this->assertSame(60, $size[1]);
    }

    public function test_store_replaces_the_previous_avatar_at_the_same_path(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        SiteUserAvatar::store($siteUser, $this->fakeImage(100, 100));
        $siteUser->refresh();
        $firstPath = $siteUser->avatar_path;

        SiteUserAvatar::store($siteUser, $this->fakeImage(50, 50));
        $siteUser->refresh();

        $this->assertSame($firstPath, $siteUser->avatar_path);
    }

    public function test_destroy_removes_the_file_and_clears_the_column(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        SiteUserAvatar::store($siteUser, $this->fakeImage(100, 100));
        $siteUser->refresh();
        $path = $siteUser->avatar_path;

        SiteUserAvatar::destroy($siteUser);
        $siteUser->refresh();

        $this->assertNull($siteUser->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_avatar_url_falls_back_to_the_discord_avatar_without_a_custom_photo(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'discord_avatar_url' => 'https://cdn.discordapp.com/x.png']);

        $this->assertSame('https://cdn.discordapp.com/x.png', $siteUser->avatar_url);
    }

    public function test_avatar_url_prefers_the_custom_photo_over_discord(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'discord_avatar_url' => 'https://cdn.discordapp.com/x.png']);
        SiteUserAvatar::store($siteUser, $this->fakeImage(100, 100));
        $siteUser->refresh();

        $this->assertNotSame('https://cdn.discordapp.com/x.png', $siteUser->avatar_url);
        $this->assertStringContainsString('site-user-avatars', $siteUser->avatar_url);
    }

    public function test_store_throws_and_does_not_touch_the_database_if_the_disk_write_fails(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $failingDisk = \Mockery::mock();
        $failingDisk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($failingDisk);

        $thrown = null;
        try {
            SiteUserAvatar::store($siteUser, $this->fakeImage(100, 100));
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertNull($siteUser->fresh()->avatar_path);
    }
}
