<?php

namespace App\Notifications;

use App\Models\GalleryComment;
use Illuminate\Notifications\Notification;

/**
 * Notifica al dueño de un GalleryItem que le comentaron (2026-09-02, modulo
 * de galeria). Sin ShouldQueue a proposito -- el sitio no tiene
 * infraestructura de queue workers, se crea sincronico en el mismo request
 * que el comentario (un solo insert, costo despreciable). Canal `database`
 * (tabla `notifications`, ver esa migracion) es el unico usado.
 */
class GalleryCommentPosted extends Notification
{
    public function __construct(private GalleryComment $comment)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $galleryItem = $this->comment->galleryItem;

        return [
            'gallery_item_id' => $galleryItem->id,
            'gallery_item_title' => $galleryItem->title,
            'comment_id' => $this->comment->id,
            'actor_site_user_id' => $this->comment->site_user_id,
            'actor_name' => $this->comment->siteUser->discord_username,
        ];
    }
}
