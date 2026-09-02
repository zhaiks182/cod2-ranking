<?php

namespace Tests\Feature;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editar el titulo de un item de galeria ya subido (2026-09-02, a pedido
 * del dueño) -- solo el titulo, ni el archivo ni la partida vinculada se
 * pueden editar despues de subir (alcance acotado a proposito, ver
 * GalleryController::update()).
 */
class GalleryEditTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(SiteUser $owner): GalleryItem
    {
        return GalleryItem::create([
            'site_user_id' => $owner->id, 'title' => 'Headshot', 'type' => 'video',
            'file_path' => "gallery/{$owner->id}/x.mp4", 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
        ]);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $this->get(route('gallery.edit', $item))->assertRedirect(route('login'));
        $this->put(route('gallery.update', $item), ['title' => 'x'])->assertRedirect(route('login'));
    }

    public function test_the_owner_can_edit_the_title(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $response = $this->actingAs($owner, 'site')->put(route('gallery.update', $item), ['title' => 'Ace en Toujane']);

        $response->assertRedirect(route('gallery.show', $item));
        $this->assertSame('Ace en Toujane', $item->fresh()->title);
    }

    public function test_another_user_cannot_view_the_edit_form(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $other = SiteUser::create(['discord_id' => '2', 'discord_username' => 'other']);
        $item = $this->makeItem($owner);

        $this->actingAs($other, 'site')->get(route('gallery.edit', $item))->assertForbidden();
    }

    public function test_another_user_cannot_update_the_title(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $other = SiteUser::create(['discord_id' => '2', 'discord_username' => 'other']);
        $item = $this->makeItem($owner);

        $response = $this->actingAs($other, 'site')->put(route('gallery.update', $item), ['title' => 'Robado']);

        $response->assertForbidden();
        $this->assertSame('Headshot', $item->fresh()->title);
    }

    public function test_the_title_is_required(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $this->actingAs($owner, 'site')->put(route('gallery.update', $item), ['title' => ''])
            ->assertSessionHasErrors('title');
        $this->assertSame('Headshot', $item->fresh()->title);
    }

    public function test_updating_never_touches_the_file_or_the_linked_match(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);
        $originalPath = $item->file_path;

        $this->actingAs($owner, 'site')->put(route('gallery.update', $item), ['title' => 'Nuevo título', 'match_id' => 999]);

        $item->refresh();
        $this->assertSame($originalPath, $item->file_path);
        $this->assertNull($item->match_id);
    }

    public function test_the_owner_can_edit_the_category(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $this->actingAs($owner, 'site')->put(route('gallery.update', $item), ['title' => 'Headshot', 'category' => 'buenos_tiros']);

        $this->assertSame('buenos_tiros', $item->fresh()->category);
    }

    public function test_updating_rejects_a_category_outside_the_predefined_list(): void
    {
        $owner = SiteUser::create(['discord_id' => '1', 'discord_username' => 'owner']);
        $item = $this->makeItem($owner);

        $this->actingAs($owner, 'site')->put(route('gallery.update', $item), ['title' => 'Headshot', 'category' => 'no_existe'])
            ->assertSessionHasErrors('category');
    }
}
