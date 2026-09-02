<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\GalleryLike;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryLikeTest extends TestCase
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

    public function test_toggling_creates_a_like(): void
    {
        $item = $this->makeItem();
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->post(route('gallery.like', $item));

        $this->assertSame(1, GalleryLike::where('gallery_item_id', $item->id)->count());
    }

    public function test_toggling_again_removes_the_like(): void
    {
        $item = $this->makeItem();
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')->post(route('gallery.like', $item));
        $this->actingAs($siteUser, 'site')->post(route('gallery.like', $item));

        $this->assertSame(0, GalleryLike::where('gallery_item_id', $item->id)->count());
    }

    public function test_two_different_users_can_each_like_the_same_item(): void
    {
        $item = $this->makeItem();
        $a = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        $b = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);

        $this->actingAs($a, 'site')->post(route('gallery.like', $item));
        $this->actingAs($b, 'site')->post(route('gallery.like', $item));

        $this->assertSame(2, GalleryLike::where('gallery_item_id', $item->id)->count());
    }

    public function test_a_guest_cannot_like(): void
    {
        $item = $this->makeItem();

        $this->post(route('gallery.like', $item))->assertRedirect(route('login'));
    }
}
