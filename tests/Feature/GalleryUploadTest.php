<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\Setting;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_upload(): void
    {
        $this->post(route('gallery.store'), ['title' => 'x'])->assertRedirect(route('login'));
    }

    public function test_uploads_a_valid_image(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'Ace en Toujane',
            'file' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ]);

        $response->assertRedirect();
        $item = GalleryItem::firstWhere('title', 'Ace en Toujane');
        $this->assertNotNull($item);
        $this->assertSame('image', $item->type);
        $this->assertSame($siteUser->id, $item->site_user_id);
        Storage::disk('public')->assertExists($item->file_path);
    }

    public function test_uploads_a_valid_video(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'Clip',
            'file' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
        ]);

        $response->assertRedirect();
        $item = GalleryItem::firstWhere('title', 'Clip');
        $this->assertSame('video', $item->type);
    }

    public function test_rejects_a_disallowed_file_format(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('video.mov', 100, 'video/quicktime'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, GalleryItem::count());
    }

    public function test_rejects_a_file_that_exceeds_the_remaining_quota(): void
    {
        Storage::fake('public');
        Setting::current()->update(['gallery_quota_mb' => 1]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('grande.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, GalleryItem::count());
    }

    public function test_saves_the_optional_linked_match(): void
    {
        Storage::fake('public');
        $server = \App\Models\Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $match = \App\Models\GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->image('foto.jpg'),
            'match_id' => $match->id,
        ]);

        $this->assertSame($match->id, GalleryItem::first()->match_id);
    }
}
