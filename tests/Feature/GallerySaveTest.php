<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\GallerySave;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Guardar" un video/imagen para verlo despues (2026-09-02, a pedido del
 * dueño, tipo "Ver más tarde" de YouTube). Mismo patron que GalleryLikeTest.
 */
class GallerySaveTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(): GalleryItem
    {
        $owner = SiteUser::create(['discord_id' => '99', 'discord_username' => 'owner']);

        return GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => 'gallery/99/x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);
    }

    public function test_toggling_creates_a_save(): void
    {
        $item = $this->makeItem();
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->post(route('gallery.save', $item));

        $this->assertSame(1, GallerySave::where('gallery_item_id', $item->id)->count());
    }

    public function test_toggling_again_removes_the_save(): void
    {
        $item = $this->makeItem();
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->post(route('gallery.save', $item));
        $this->actingAs($siteUser, 'site')->post(route('gallery.save', $item));

        $this->assertSame(0, GallerySave::where('gallery_item_id', $item->id)->count());
    }

    public function test_a_guest_cannot_save(): void
    {
        $item = $this->makeItem();

        $this->post(route('gallery.save', $item))->assertRedirect(route('login'));
    }

    public function test_a_guest_is_redirected_when_visiting_saved_items(): void
    {
        $this->get(route('gallery.saved'))->assertRedirect(route('login'));
    }

    public function test_the_saved_page_lists_only_items_the_user_saved(): void
    {
        $itemA = $this->makeItem();
        $itemB = GalleryItem::create([
            'site_user_id' => $itemA->site_user_id, 'title' => 'No guardado', 'type' => 'image',
            'file_path' => 'gallery/99/y.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        GallerySave::create(['gallery_item_id' => $itemA->id, 'site_user_id' => $siteUser->id, 'created_at' => now()]);

        $response = $this->actingAs($siteUser, 'site')->get(route('gallery.saved'));

        $response->assertOk();
        $ids = collect($response->viewData('items')->items())->pluck('id')->all();
        $this->assertSame([$itemA->id], $ids);
    }

    public function test_the_saved_page_does_not_show_items_another_user_saved(): void
    {
        $item = $this->makeItem();
        $other = SiteUser::create(['discord_id' => '2', 'discord_username' => 'other']);
        GallerySave::create(['gallery_item_id' => $item->id, 'site_user_id' => $other->id, 'created_at' => now()]);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $response = $this->actingAs($siteUser, 'site')->get(route('gallery.saved'));

        $this->assertTrue($response->viewData('items')->isEmpty());
    }
}
