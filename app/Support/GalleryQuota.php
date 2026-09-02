<?php

namespace App\Support;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use App\Models\Setting;

/**
 * Cuota de almacenamiento de la galeria (2026-09-02) -- 100MB por usuario
 * por default, editable desde /adm_cod2/galeria (settings.gallery_quota_mb).
 * Ver docs/superpowers/specs/2026-09-02-galeria-multimedia-design.md.
 */
class GalleryQuota
{
    private const DEFAULT_MB = 100;

    public static function limitBytes(): int
    {
        $mb = Setting::current()->gallery_quota_mb ?? self::DEFAULT_MB;

        return $mb * 1024 * 1024;
    }

    public static function usedBytes(SiteUser $siteUser): int
    {
        return (int) GalleryItem::where('site_user_id', $siteUser->id)->sum('size_bytes');
    }

    public static function remainingBytes(SiteUser $siteUser): int
    {
        return self::limitBytes() - self::usedBytes($siteUser);
    }

    public static function fits(SiteUser $siteUser, int $newFileBytes): bool
    {
        return $newFileBytes <= self::remainingBytes($siteUser);
    }
}
