<?php

namespace App\Support;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sube un archivo a la galeria (2026-09-02). Disco publico, servido directo
 * por Apache (no un controller de streaming como Demos -- este contenido es
 * publico por diseño, ver la spec). Nombre de archivo con UUID, nunca el
 * original -- evita colisiones y caracteres raros en el filesystem, mismo
 * motivo que ya usan las demos.
 */
class GalleryUpload
{
    private const VIDEO_MIMES = ['video/mp4', 'video/webm'];

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function store(SiteUser $siteUser, UploadedFile $file, string $title, ?int $matchId): GalleryItem
    {
        $mimeType = $file->getMimeType();
        $type = self::typeFor($mimeType);
        $sizeBytes = $file->getSize();

        if (! GalleryQuota::fits($siteUser, $sizeBytes)) {
            $remainingMb = round(GalleryQuota::remainingBytes($siteUser) / 1024 / 1024, 1);
            $limitMb = round(GalleryQuota::limitBytes() / 1024 / 1024);
            throw new RuntimeException("Te quedan {$remainingMb}MB de tu cuota de {$limitMb}MB.");
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $fileName = Str::uuid()->toString().'.'.$ext;
        $path = Storage::disk('public')->putFileAs("gallery/{$siteUser->id}", $file, $fileName);

        return GalleryItem::create([
            'site_user_id' => $siteUser->id,
            'title' => $title,
            'type' => $type,
            'file_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'match_id' => $matchId,
        ]);
    }

    public static function destroy(GalleryItem $item): void
    {
        Storage::disk('public')->delete($item->file_path);
        $item->delete();
    }

    private static function typeFor(string $mimeType): string
    {
        if (in_array($mimeType, self::VIDEO_MIMES, true)) {
            return 'video';
        }
        if (in_array($mimeType, self::IMAGE_MIMES, true)) {
            return 'image';
        }

        throw new RuntimeException("Formato de archivo no permitido: {$mimeType}");
    }
}
