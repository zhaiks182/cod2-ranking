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

    /**
     * UploadedFile::fake()->create() genera bytes al azar, no un video real
     * -- ffmpeg no puede extraer un frame de ahi. Confirma que eso no
     * bloquea la subida, solo se queda sin miniatura (mismo criterio
     * defensivo del resto del modulo).
     */
    public function test_a_fake_video_without_real_video_data_uploads_without_a_thumbnail(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'Clip',
            'file' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
        ]);

        $this->assertNull(GalleryItem::firstWhere('title', 'Clip')->thumbnail_path);
    }

    /**
     * Con un archivo mp4 real (tests/Fixtures/tiny-video.mp4, generado con
     * ffmpeg: 1s, 64x64, color solido) confirma el camino exitoso completo:
     * ffmpeg extrae un frame real y queda guardado en el disco publico.
     */
    public function test_a_real_video_gets_a_generated_thumbnail(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        $fixture = base_path('tests/Fixtures/tiny-video.mp4');

        $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'Clip real',
            'file' => new UploadedFile($fixture, 'clip.mp4', 'video/mp4', null, true),
        ]);

        $item = GalleryItem::firstWhere('title', 'Clip real');
        $this->assertNotNull($item->thumbnail_path);
        Storage::disk('public')->assertExists($item->thumbnail_path);
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

    /**
     * Tope de 30MB solo para video (2026-09-02, a pedido del dueño), APARTE
     * de la cuota total -- rechaza incluso si el usuario todavia tiene
     * cuota de sobra (100MB default, video de 40MB entra en la cuota pero
     * no en el tope de video).
     */
    public function test_rejects_a_video_larger_than_the_video_max_even_with_quota_to_spare(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('grande.mp4', 40 * 1024, 'video/mp4'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, GalleryItem::count());
    }

    public function test_allows_a_video_within_the_video_max(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('ok.mp4', 25 * 1024, 'video/mp4'),
        ]);

        $response->assertRedirect();
        $this->assertSame(1, GalleryItem::count());
    }

    public function test_an_image_is_not_subject_to_the_video_max(): void
    {
        Storage::fake('public');
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('grande.jpg', 40 * 1024, 'image/jpeg'),
        ]);

        $response->assertRedirect();
        $this->assertSame(1, GalleryItem::count());
    }

    public function test_the_admin_configured_video_max_is_respected(): void
    {
        Storage::fake('public');
        Setting::current()->update(['gallery_video_max_mb' => 10]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->post(route('gallery.store'), [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('clip.mp4', 15 * 1024, 'video/mp4'),
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
