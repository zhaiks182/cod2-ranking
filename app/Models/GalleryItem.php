<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class GalleryItem extends Model
{
    protected $fillable = [
        'site_user_id', 'title', 'type', 'file_path', 'thumbnail_path', 'mime_type', 'size_bytes', 'match_id', 'is_featured',
    ];

    protected $casts = ['is_featured' => 'boolean'];

    public function siteUser(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(GalleryComment::class)->orderBy('created_at');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(GalleryLike::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }
}
