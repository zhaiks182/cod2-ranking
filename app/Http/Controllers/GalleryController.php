<?php

namespace App\Http\Controllers;

use App\Models\GalleryComment;
use App\Models\GalleryItem;
use App\Models\GalleryLike;
use App\Models\GameMatch;
use App\Notifications\GalleryCommentPosted;
use App\Support\GalleryQuota;
use App\Support\GalleryUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Galeria multimedia (2026-09-02) -- videos/imagenes subidos por cualquier
 * cuenta con sesion de Discord, sin requerir perfil de jugador reclamado.
 * Ver docs/superpowers/specs/2026-09-02-galeria-multimedia-design.md.
 */
class GalleryController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('tipo');

        $items = GalleryItem::with(['siteUser', 'match'])
            ->withCount(['comments', 'likes'])
            ->when(in_array($type, ['image', 'video'], true), fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(24)
            ->withQueryString();

        return view('gallery.index', compact('items', 'type'));
    }

    public function show(GalleryItem $galleryItem)
    {
        $galleryItem->load(['siteUser', 'match', 'comments.siteUser']);
        $likesCount = $galleryItem->likes()->count();
        $liked = Auth::guard('site')->check()
            && GalleryLike::where('gallery_item_id', $galleryItem->id)->where('site_user_id', Auth::guard('site')->id())->exists();

        return view('gallery.show', compact('galleryItem', 'likesCount', 'liked'));
    }

    public function create()
    {
        $siteUser = Auth::guard('site')->user();
        $remainingMb = round(GalleryQuota::remainingBytes($siteUser) / 1024 / 1024, 1);
        $limitMb = round(GalleryQuota::limitBytes() / 1024 / 1024);
        $matches = $this->recentMatches();

        return view('gallery.create', compact('remainingMb', 'limitMb', 'matches'));
    }

    private function recentMatches()
    {
        return GameMatch::visibleInListing()
            ->where('gametype', 'sd')
            ->where('is_backfilled', false)
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();
    }

    public function store(Request $request)
    {
        $siteUser = Auth::guard('site')->user();
        $remainingKb = (int) floor(GalleryQuota::remainingBytes($siteUser) / 1024);

        if ($remainingKb <= 0) {
            return back()->withErrors('Ya usaste toda tu cuota de almacenamiento.')->withInput();
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:mp4,webm,jpg,jpeg,png,webp,gif', "max:{$remainingKb}"],
            'match_id' => ['nullable', 'integer', 'exists:matches,id'],
        ]);

        try {
            $item = GalleryUpload::store($siteUser, $request->file('file'), $data['title'], $data['match_id'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors($e->getMessage())->withInput();
        }

        return redirect()->route('gallery.show', $item)->with('status', 'Subido correctamente.');
    }

    public function edit(GalleryItem $galleryItem)
    {
        abort_unless($galleryItem->site_user_id === Auth::guard('site')->id(), 403);

        return view('gallery.edit', compact('galleryItem'));
    }

    /**
     * Solo el titulo -- ni el archivo ni la partida vinculada se pueden
     * editar despues de subir (a pedido del dueño, alcance acotado a
     * proposito: reemplazar el archivo o editar el video en si -recortar,
     * etc- quedaron fuera, necesitarian procesamiento de video en un VPS de
     * 1 core).
     */
    public function update(Request $request, GalleryItem $galleryItem)
    {
        abort_unless($galleryItem->site_user_id === Auth::guard('site')->id(), 403);

        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);

        $galleryItem->update(['title' => $data['title']]);

        return redirect()->route('gallery.show', $galleryItem)->with('status', 'Actualizado.');
    }

    public function destroy(GalleryItem $galleryItem)
    {
        abort_unless($galleryItem->site_user_id === Auth::guard('site')->id(), 403);

        GalleryUpload::destroy($galleryItem);

        return redirect()->route('gallery.index')->with('status', 'Eliminado.');
    }

    public function toggleLike(GalleryItem $galleryItem)
    {
        $siteUserId = Auth::guard('site')->id();

        $like = GalleryLike::where('gallery_item_id', $galleryItem->id)->where('site_user_id', $siteUserId)->first();

        if ($like) {
            $like->delete();
        } else {
            GalleryLike::create(['gallery_item_id' => $galleryItem->id, 'site_user_id' => $siteUserId, 'created_at' => now()]);
        }

        return back();
    }

    public function storeComment(Request $request, GalleryItem $galleryItem)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $comment = GalleryComment::create([
            'gallery_item_id' => $galleryItem->id,
            'site_user_id' => Auth::guard('site')->id(),
            'body' => $data['body'],
        ]);

        if ($galleryItem->site_user_id !== $comment->site_user_id) {
            $galleryItem->siteUser->notify(new GalleryCommentPosted($comment));
        }

        return back()->with('status', 'Comentario publicado.');
    }

    public function destroyComment(GalleryComment $galleryComment)
    {
        $siteUserId = Auth::guard('site')->id();
        $isAuthor = $galleryComment->site_user_id === $siteUserId;
        $isOwner = $galleryComment->galleryItem->site_user_id === $siteUserId;

        abort_unless($isAuthor || $isOwner, 403);

        $galleryComment->delete();

        return back()->with('status', 'Comentario eliminado.');
    }
}
