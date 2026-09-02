<?php

namespace App\Http\Controllers;

use App\Models\GalleryComment;
use App\Models\GalleryItem;
use App\Models\GalleryLike;
use App\Models\GallerySave;
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
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(24)
            ->withQueryString();

        return view('gallery.index', compact('items', 'type'));
    }

    public function show(GalleryItem $galleryItem)
    {
        $galleryItem->load(['siteUser', 'match', 'comments.siteUser']);
        $likesCount = $galleryItem->likes()->count();
        $siteUserId = Auth::guard('site')->id();
        $liked = $siteUserId && GalleryLike::where('gallery_item_id', $galleryItem->id)->where('site_user_id', $siteUserId)->exists();
        $saved = $siteUserId && GallerySave::where('gallery_item_id', $galleryItem->id)->where('site_user_id', $siteUserId)->exists();

        return view('gallery.show', compact('galleryItem', 'likesCount', 'liked', 'saved'));
    }

    /** "Guardados" del usuario logueado -- tipo "Ver más tarde" de YouTube. */
    public function saved()
    {
        $items = GalleryItem::with(['siteUser', 'match'])
            ->withCount(['comments', 'likes'])
            ->whereHas('saves', fn ($q) => $q->where('site_user_id', Auth::guard('site')->id()))
            ->orderByDesc('created_at')
            ->paginate(24);

        return view('gallery.saved', compact('items'));
    }

    public function create()
    {
        $siteUser = Auth::guard('site')->user();
        $remainingMb = round(GalleryQuota::remainingBytes($siteUser) / 1024 / 1024, 1);
        $limitMb = round(GalleryQuota::limitBytes() / 1024 / 1024);
        $videoMaxMb = round(GalleryQuota::videoMaxBytes() / 1024 / 1024);
        $matches = $this->recentMatches();

        return view('gallery.create', compact('remainingMb', 'limitMb', 'videoMaxMb', 'matches'));
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

        // El tope de video (30MB por default, ver GalleryQuota::videoMaxBytes())
        // es APARTE de la cuota total -- se aplica ademas del "max" dinamico de
        // la cuota, no en su lugar, asi que el limite real de un video es el mas
        // chico de los dos.
        $videoMaxKb = (int) floor(GalleryQuota::videoMaxBytes() / 1024);
        $maxKb = min($remainingKb, $videoMaxKb);
        $isVideoUpload = in_array(strtolower($request->file('file')?->getClientOriginalExtension() ?? ''), ['mp4', 'webm'], true);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:mp4,webm,jpg,jpeg,png,webp,gif', 'max:'.($isVideoUpload ? $maxKb : $remainingKb)],
            'match_id' => ['nullable', 'integer', 'exists:matches,id'],
        ], [
            'file.max' => $isVideoUpload
                ? 'Los videos no pueden pesar más de '.round($videoMaxKb / 1024).'MB.'
                : 'El archivo excede tu cuota disponible.',
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

    public function toggleSave(GalleryItem $galleryItem)
    {
        $siteUserId = Auth::guard('site')->id();

        $save = GallerySave::where('gallery_item_id', $galleryItem->id)->where('site_user_id', $siteUserId)->first();

        if ($save) {
            $save->delete();
        } else {
            GallerySave::create(['gallery_item_id' => $galleryItem->id, 'site_user_id' => $siteUserId, 'created_at' => now()]);
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
