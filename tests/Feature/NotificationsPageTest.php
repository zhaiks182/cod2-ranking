<?php

namespace Tests\Feature;

use App\Models\GalleryComment;
use App\Models\GalleryItem;
use App\Models\SiteUser;
use App\Notifications\GalleryCommentPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_lists_only_the_logged_in_users_notifications(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $other = SiteUser::create(['discord_id' => '2', 'discord_username' => 'other']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => 'gallery/1/x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);
        $commenter = SiteUser::create(['discord_id' => '3', 'discord_username' => 'c']);
        $comment = GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'x']);
        $owner->notify(new GalleryCommentPosted($comment));

        $response = $this->actingAs($owner, 'site')->get(route('notifications.index'));
        $response->assertOk();
        $this->assertSame(1, $owner->notifications()->count());

        $otherResponse = $this->actingAs($other, 'site')->get(route('notifications.index'));
        $otherResponse->assertOk();
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_visiting_the_page_marks_unread_notifications_as_read(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => 'gallery/1/x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);
        $commenter = SiteUser::create(['discord_id' => '3', 'discord_username' => 'c']);
        $comment = GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'x']);
        $owner->notify(new GalleryCommentPosted($comment));

        $this->assertSame(1, $owner->unreadNotifications()->count());

        $this->actingAs($owner, 'site')->get(route('notifications.index'));

        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
    }
}
