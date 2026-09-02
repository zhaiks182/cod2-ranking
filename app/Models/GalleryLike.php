<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryLike extends Model
{
    public $timestamps = false;

    protected $fillable = ['gallery_item_id', 'site_user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function galleryItem(): BelongsTo
    {
        return $this->belongsTo(GalleryItem::class);
    }

    public function siteUser(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class);
    }
}
