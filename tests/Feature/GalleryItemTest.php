<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryItemTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(SiteUser $owner, int $sizeBytes = 1024): GalleryItem
    {
        Storage::disk('public')->put("gallery/{$owner->id}/x.jpg", 'contenido');

        return GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => "gallery/{$owner->id}/x.jpg", 'mime_type' => 'image/jpeg', 'size_bytes' => $sizeBytes,
        ]);
    }

    public function test_the_owner_can_delete_their_own_item(): void
    {
        Storage::fake('public');
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        $item = $this->makeItem($owner);

        $response = $this->actingAs($owner, 'site')->delete(route('gallery.destroy', $item));

        $response->assertRedirect(route('gallery.index'));
        $this->assertNull(GalleryItem::find($item->id));
        Storage::disk('public')->assertMissing($item->file_path);
    }

    public function test_another_user_cannot_delete_it(): void
    {
        Storage::fake('public');
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        $other = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);
        $item = $this->makeItem($owner);

        $response = $this->actingAs($other, 'site')->delete(route('gallery.destroy', $item));

        $response->assertForbidden();
        $this->assertNotNull(GalleryItem::find($item->id));
    }

    public function test_a_guest_cannot_delete_it(): void
    {
        Storage::fake('public');
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        $item = $this->makeItem($owner);

        $this->delete(route('gallery.destroy', $item))->assertRedirect(route('login'));
    }

    public function test_deleting_an_item_frees_up_the_quota(): void
    {
        Storage::fake('public');
        \App\Models\Setting::current()->update(['gallery_quota_mb' => 1]);
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);
        $item = $this->makeItem($owner, 1024 * 1024); // usa toda la cuota de 1MB

        $this->assertSame(0, \App\Support\GalleryQuota::remainingBytes($owner));

        $this->actingAs($owner, 'site')->delete(route('gallery.destroy', $item));

        $this->assertSame(1024 * 1024, \App\Support\GalleryQuota::remainingBytes($owner));
    }
}
