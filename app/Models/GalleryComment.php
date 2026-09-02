<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryComment extends Model
{
    protected $fillable = ['gallery_item_id', 'site_user_id', 'body'];

    public function galleryItem(): BelongsTo
    {
        return $this->belongsTo(GalleryItem::class);
    }

    public function siteUser(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class);
    }
}
