<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contador de reproducciones (2026-09-02, a pedido del dueño) -- disparado
 * por el evento "play" del <video>, ver GalleryController::registerPlay().
 * Publico, sin sesion (cualquiera que mire cuenta, como YouTube).
 */
class GalleryPlayCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_play_increments_the_video_counter(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);

        $this->post(route('gallery.play', $item));
        $this->post(route('gallery.play', $item));

        $this->assertSame(2, $item->fresh()->views_count);
    }

    public function test_a_guest_can_register_a_play_without_a_session(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);

        $response = $this->post(route('gallery.play', $item));

        $response->assertNoContent();
    }

    public function test_an_image_never_increments_the_play_counter(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => 'gallery/1/x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);

        $this->post(route('gallery.play', $item));

        $this->assertSame(0, $item->fresh()->views_count);
    }
}
