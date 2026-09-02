<?php

namespace Tests\Feature;

use App\Models\GalleryComment;
use App\Models\GalleryItem;
use App\Models\SiteUser;
use App\Notifications\GalleryCommentPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GalleryCommentTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(SiteUser $owner): GalleryItem
    {
        return GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => "gallery/{$owner->id}/x.jpg", 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);
    }

    public function test_a_logged_in_user_can_comment(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);

        $this->actingAs($commenter, 'site')->post(route('gallery.comments.store', $item), ['body' => 'Que crack']);

        $this->assertSame(1, GalleryComment::where('gallery_item_id', $item->id)->count());
    }

    public function test_a_guest_cannot_comment(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $this->post(route('gallery.comments.store', $item), ['body' => 'x'])->assertRedirect(route('login'));
    }

    public function test_commenting_notifies_the_owner(): void
    {
        Notification::fake();
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);

        $this->actingAs($commenter, 'site')->post(route('gallery.comments.store', $item), ['body' => 'Que crack']);

        Notification::assertSentTo($owner, GalleryCommentPosted::class);
    }

    public function test_commenting_on_your_own_item_does_not_notify_yourself(): void
    {
        Notification::fake();
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $this->actingAs($owner, 'site')->post(route('gallery.comments.store', $item), ['body' => 'mi propio comentario']);

        Notification::assertNotSentTo($owner, GalleryCommentPosted::class);
    }

    public function test_the_author_can_delete_their_own_comment(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);
        $comment = GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'x']);

        $response = $this->actingAs($commenter, 'site')->delete(route('gallery.comments.destroy', $comment));

        $response->assertRedirect();
        $this->assertNull(GalleryComment::find($comment->id));
    }

    public function test_the_item_owner_can_delete_a_comment_someone_else_made_on_it(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);
        $comment = GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'x']);

        $response = $this->actingAs($owner, 'site')->delete(route('gallery.comments.destroy', $comment));

        $response->assertRedirect();
        $this->assertNull(GalleryComment::find($comment->id));
    }

    public function test_a_third_party_cannot_delete_the_comment(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);
        $thirdParty = SiteUser::create(['discord_id' => '3', 'discord_username' => 'c']);
        $comment = GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'x']);

        $response = $this->actingAs($thirdParty, 'site')->delete(route('gallery.comments.destroy', $comment));

        $response->assertForbidden();
        $this->assertNotNull(GalleryComment::find($comment->id));
    }
}
