<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chequeo de renderizado puro (2026-09-02) -- las otras suites de Gallery ya
 * ejercitan la logica de cada endpoint, pero varias vistas (index/create/
 * notifications) nunca se renderizan completas en esos tests. Un error de
 * sintaxis Blade recien se ve al renderizar, no al hacer un POST que
 * redirige antes de tocar la vista.
 */
class GalleryPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_index_renders_with_items(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Mi video', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);

        $this->get(route('gallery.index'))->assertOk()->assertSee('Mi video');
    }

    public function test_gallery_index_renders_empty(): void
    {
        $this->get(route('gallery.index'))->assertOk();
    }

    public function test_featured_items_are_listed_first_regardless_of_upload_date(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $older = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Viejo pero destacado', 'type' => 'image',
            'file_path' => 'gallery/1/a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
            'is_featured' => true,
        ]);
        $older->forceFill(['created_at' => now()->subDays(5)])->save();
        $newer = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Nuevo sin destacar', 'type' => 'image',
            'file_path' => 'gallery/1/b.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);

        $response = $this->get(route('gallery.index'));

        $response->assertOk();
        $ids = collect($response->viewData('items')->items())->pluck('id')->all();
        $this->assertSame([$older->id, $newer->id], $ids);
        $response->assertSee('Destacado');
    }

    public function test_gallery_show_renders(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Mi video', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);

        $this->get(route('gallery.show', $item))->assertOk()->assertSee('Mi video');
    }

    public function test_gallery_index_filters_by_category(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $troll = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Un troll', 'type' => 'image',
            'file_path' => 'gallery/1/a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024, 'category' => 'troll',
        ]);
        $tiros = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Buen tiro', 'type' => 'image',
            'file_path' => 'gallery/1/b.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024, 'category' => 'buenos_tiros',
        ]);

        $response = $this->get(route('gallery.index', ['categoria' => 'troll']));

        $response->assertOk();
        $ids = collect($response->viewData('items')->items())->pluck('id')->all();
        $this->assertSame([$troll->id], $ids);
        $response->assertSee('Troll');
    }

    public function test_gallery_show_renders_share_and_download_buttons(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Mi video', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);

        // Compartir/Descargar no requieren sesion -- se ven sin login.
        $this->get(route('gallery.show', $item))
            ->assertOk()
            ->assertSee('Compartir')
            ->assertSee('Descargar');
    }

    /**
     * Al pegar el link de un item en Discord/redes debe verse el titulo y
     * autor de ESE item, no solo la meta generica del sitio (2026-09-02, a
     * pedido del dueño) -- layouts/app.blade.php ya cae al default cuando
     * una vista no pisa @section('og_title'|'og_description'|'og_image').
     */
    public function test_gallery_show_sets_item_specific_open_graph_tags_for_an_image(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Ace en Toujane', 'type' => 'image',
            'file_path' => 'gallery/1/x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);

        $response = $this->get(route('gallery.show', $item));

        $response->assertOk();
        $response->assertSee('<meta property="og:title" content="Ace en Toujane">', false);
        $response->assertSee('owner', false);
        $response->assertSee($item->url(), false);
    }

    public function test_gallery_show_sets_open_graph_title_for_a_video_without_a_generated_image(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Clip de headshot', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);

        $response = $this->get(route('gallery.show', $item));

        $response->assertOk();
        $response->assertSee('<meta property="og:title" content="Clip de headshot">', false);
    }

    public function test_gallery_show_displays_the_play_count_for_a_video(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Mi video', 'type' => 'video',
            'file_path' => 'gallery/1/x.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);
        $item->forceFill(['views_count' => 42])->save();

        $this->get(route('gallery.show', $item))->assertOk()->assertSee('42');
    }

    public function test_gallery_create_renders_for_a_logged_in_user(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->get(route('gallery.create'))->assertOk();
    }

    public function test_notifications_index_renders_empty(): void
    {
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->get(route('notifications.index'))->assertOk();
    }
}
