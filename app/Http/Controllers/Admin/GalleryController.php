<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\GalleryComment;
use App\Models\GalleryItem;
use App\Models\Setting;
use App\Support\GalleryUpload;
use Illuminate\Http\Request;

/**
 * Moderacion de la galeria (2026-09-02) -- mismo patron que /adm_cod2/demos:
 * cuota editable arriba, listado completo de lo subido, borrar cualquier
 * item o comentario. Ver docs/superpowers/specs/2026-09-02-galeria-
 * multimedia-design.md.
 */
class GalleryController extends Controller
{
    public function index()
    {
        $items = GalleryItem::with('siteUser')
            ->withCount('comments')
            ->orderByDesc('created_at')
            ->paginate(20);

        $setting = Setting::current();

        return view('admin.gallery.index', compact('items', 'setting'));
    }

    public function updateQuota(Request $request)
    {
        $data = $request->validate(['gallery_quota_mb' => ['required', 'integer', 'min:1', 'max:10000']]);

        Setting::current()->update(['gallery_quota_mb' => $data['gallery_quota_mb']]);

        AdminAction::record('gallery.quota-update', "Cambió la cuota de galería a {$data['gallery_quota_mb']}MB por usuario");

        return back()->with('status', 'Cuota actualizada.');
    }

    public function destroy(GalleryItem $galleryItem)
    {
        $label = $galleryItem->title;
        $owner = $galleryItem->siteUser->discord_username;

        GalleryUpload::destroy($galleryItem);

        AdminAction::record('gallery.destroy', "Eliminó el archivo de galería \"{$label}\" de {$owner}");

        return back()->with('status', "Archivo eliminado (\"{$label}\").");
    }

    public function show(GalleryItem $galleryItem)
    {
        $comments = $galleryItem->comments()->with('siteUser')->get();

        return view('admin.gallery.show', compact('galleryItem', 'comments'));
    }

    public function destroyComment(GalleryComment $galleryComment)
    {
        $itemId = $galleryComment->gallery_item_id;
        $author = $galleryComment->siteUser->discord_username;

        $galleryComment->delete();

        AdminAction::record('gallery.comment-destroy', "Eliminó un comentario de {$author} en la galería");

        return redirect()->route('admin.gallery.show', $itemId)->with('status', 'Comentario eliminado.');
    }
}
