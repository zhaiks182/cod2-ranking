<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class WeaponCatalog
{
    public static function label(?string $code): string
    {
        if ($code === null || $code === '' || $code === 'none') {
            return '—';
        }

        $name = preg_replace('/_mp$/', '', $code);
        $name = str_replace('_', ' ', $name);

        return ucwords($name);
    }

    /**
     * Public URL of the transparent-cutout icon for this weapon code, or null if
     * none exists — most (but not all, see the "Bren" gap) weapon codes have one,
     * cropped from in-game loadout-menu screenshots (2026-08-15, see storage/app/
     * public/.gitignore for why these are committed instead of upload-only like most
     * of storage/app/public).
     */
    public static function iconUrl(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        $path = "weapons/{$code}.png";

        return Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
    }
}
