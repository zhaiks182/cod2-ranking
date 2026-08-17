<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MapImage
{
    private const EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];

    /** Public URL of the uploaded image for this map code, or null if none was uploaded. */
    public static function url(string $mapCode): ?string
    {
        $mapCode = MapCatalog::normalize($mapCode);

        foreach (self::EXTENSIONS as $ext) {
            $path = "maps/{$mapCode}.{$ext}";
            if (Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->url($path);
            }
        }

        return null;
    }

    public static function store(string $mapCode, UploadedFile $file): void
    {
        $mapCode = MapCatalog::normalize($mapCode);
        self::destroy($mapCode);

        $ext = strtolower($file->getClientOriginalExtension());
        Storage::disk('public')->putFileAs('maps', $file, "{$mapCode}.{$ext}");
    }

    public static function destroy(string $mapCode): void
    {
        $mapCode = MapCatalog::normalize($mapCode);

        foreach (self::EXTENSIONS as $ext) {
            Storage::disk('public')->delete("maps/{$mapCode}.{$ext}");
        }
    }
}
