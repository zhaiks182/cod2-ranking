<?php

namespace App\Support;

use App\Models\GalleryItem;
use App\Models\SiteUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

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

    public static function store(SiteUser $siteUser, UploadedFile $file, string $title, ?int $matchId, ?string $category = null): GalleryItem
    {
        $mimeType = $file->getMimeType();
        $type = self::typeFor($mimeType);
        $sizeBytes = $file->getSize();

        if ($type === 'video' && $sizeBytes > GalleryQuota::videoMaxBytes()) {
            $videoMaxMb = round(GalleryQuota::videoMaxBytes() / 1024 / 1024);
            throw new RuntimeException("Los videos no pueden pesar más de {$videoMaxMb}MB.");
        }

        if (! GalleryQuota::fits($siteUser, $sizeBytes)) {
            $remainingMb = round(GalleryQuota::remainingBytes($siteUser) / 1024 / 1024, 1);
            $limitMb = round(GalleryQuota::limitBytes() / 1024 / 1024);
            throw new RuntimeException("Te quedan {$remainingMb}MB de tu cuota de {$limitMb}MB.");
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $uuid = Str::uuid()->toString();
        $fileName = $uuid.'.'.$ext;
        // getRealPath() se captura ANTES de moverlo -- putFileAs() no borra el
        // temporal (PHP lo hace solo al final del request), pero mejor no
        // depender de eso.
        $realPath = $file->getRealPath();
        $path = Storage::disk('public')->putFileAs("gallery/{$siteUser->id}", $file, $fileName);

        $thumbnailPath = $type === 'video'
            ? self::generateVideoThumbnail($realPath, $siteUser->id, $uuid)
            : null;

        return GalleryItem::create([
            'site_user_id' => $siteUser->id,
            'title' => $title,
            'type' => $type,
            'file_path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'match_id' => $matchId,
            'category' => $category,
        ]);
    }

    public static function destroy(GalleryItem $item): void
    {
        Storage::disk('public')->delete($item->file_path);
        if ($item->thumbnail_path) {
            Storage::disk('public')->delete($item->thumbnail_path);
        }
        $item->delete();
    }

    /**
     * Un solo frame con ffmpeg (2026-09-02, a pedido del dueño -- para que
     * el link compartido en Discord/redes muestre una imagen real, no el
     * logo generico del sitio). Extraer UN frame es liviano (nada que ver
     * con transcodificar), corre sincronico en el mismo request de subida.
     * Nunca bloquea la subida si falla -- un clip corrupto, sin pista de
     * video, o mas corto que 1s no debe impedir subir el archivo, solo se
     * queda sin miniatura (mismo criterio defensivo que el resto de este
     * modulo).
     */
    private static function generateVideoThumbnail(string $videoPath, int $siteUserId, string $uuid): ?string
    {
        $tmpOutput = sys_get_temp_dir().'/gallery_thumb_'.$uuid.'.jpg';

        // Intenta al segundo 1 (evita el primer frame, casi siempre negro/
        // en blanco en clips grabados por el juego); si el video dura menos
        // de 1s, reintenta en el segundo 0.
        foreach (['1', '0'] as $offset) {
            $process = new Process(['ffmpeg', '-y', '-ss', $offset, '-i', $videoPath, '-vframes', '1', '-vf', 'scale=640:-2', '-q:v', '4', $tmpOutput]);
            $process->setTimeout(15);
            $process->run();

            if ($process->isSuccessful() && is_file($tmpOutput)) {
                break;
            }
        }

        if (! is_file($tmpOutput)) {
            return null;
        }

        $relativePath = "gallery/{$siteUserId}/{$uuid}_thumb.jpg";
        Storage::disk('public')->put($relativePath, file_get_contents($tmpOutput));
        @unlink($tmpOutput);

        return $relativePath;
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
