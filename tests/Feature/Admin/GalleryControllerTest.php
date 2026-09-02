<?php

namespace Tests\Feature\Admin;

use App\Models\GalleryComment;
use App\Models\GalleryItem;
use App\Models\Setting;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function makeItem(): GalleryItem
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        Storage::disk('public')->put('gallery/1/x.jpg', 'contenido');

        return GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'x', 'type' => 'image',
            'file_path' => 'gallery/1/x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.gallery.index'))->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_without_the_gallery_module_is_forbidden(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['demos']]);

        $this->actingAs($user)->get(route('admin.gallery.index'))->assertForbidden();
    }

    public function test_an_admin_with_the_module_can_see_the_list(): void
    {
        $item = $this->makeItem();

        $response = $this->actingAs($this->admin())->get(route('admin.gallery.index'));

        $response->assertOk();
        $response->assertSee($item->title);
    }

    public function test_the_show_page_renders_with_comments(): void
    {
        $item = $this->makeItem();
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);
        GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'Que crack']);

        $response = $this->actingAs($this->admin())->get(route('admin.gallery.show', $item));

        $response->assertOk();
        $response->assertSee('Que crack');
    }

    public function test_updating_the_quota_is_reflected_in_the_setting(): void
    {
        $this->actingAs($this->admin())->put(route('admin.gallery.quota.update'), ['gallery_quota_mb' => 250]);

        $this->assertSame(250, Setting::current()->fresh()->gallery_quota_mb);
    }

    public function test_deleting_an_item_removes_the_file_and_audits(): void
    {
        Storage::fake('public');
        $item = $this->makeItem();
        Storage::disk('public')->put($item->file_path, 'contenido');

        $this->actingAs($this->admin())->delete(route('admin.gallery.destroy', $item));

        $this->assertNull(GalleryItem::find($item->id));
        Storage::disk('public')->assertMissing($item->file_path);
        $this->assertDatabaseHas('admin_actions', ['action' => 'gallery.destroy']);
    }

    public function test_toggling_featured_marks_and_unmarks_the_item_and_audits(): void
    {
        $item = $this->makeItem()->fresh();
        $this->assertFalse($item->is_featured);

        $this->actingAs($this->admin())->put(route('admin.gallery.toggle-featured', $item));
        $this->assertTrue($item->fresh()->is_featured);
        $this->assertDatabaseHas('admin_actions', ['action' => 'gallery.toggle-featured']);

        $this->actingAs($this->admin())->put(route('admin.gallery.toggle-featured', $item));
        $this->assertFalse($item->fresh()->is_featured);
    }

    public function test_a_guest_cannot_toggle_featured(): void
    {
        $item = $this->makeItem();

        $this->put(route('admin.gallery.toggle-featured', $item))->assertRedirect(route('admin.login'));
        $this->assertFalse($item->fresh()->is_featured);
    }

    public function test_an_admin_without_the_module_cannot_toggle_featured(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'permissions' => ['demos']]);
        $item = $this->makeItem();

        $this->actingAs($user)->put(route('admin.gallery.toggle-featured', $item))->assertForbidden();
        $this->assertFalse($item->fresh()->is_featured);
    }

    public function test_deleting_a_comment_audits(): void
    {
        $item = $this->makeItem();
        $commenter = SiteUser::create(['discord_id' => '2', 'discord_username' => 'b']);
        $comment = GalleryComment::create(['gallery_item_id' => $item->id, 'site_user_id' => $commenter->id, 'body' => 'x']);

        $this->actingAs($this->admin())->delete(route('admin.gallery.comments.destroy', $comment));

        $this->assertNull(GalleryComment::find($comment->id));
        $this->assertDatabaseHas('admin_actions', ['action' => 'gallery.comment-destroy']);
    }
}
