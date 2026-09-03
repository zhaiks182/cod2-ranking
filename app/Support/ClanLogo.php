<?php

namespace App\Support;

use App\Models\Clan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Logo de clan (2026-09-03) -- mismo patron que PlayerIcon: se re-escala con
 * GD antes de guardar (nunca se confia en el archivo original), fit-within
 * sin upscale ni crop a cuadrado, preservando canal alpha.
 */
class ClanLogo
{
    private const MAX_DIMENSION = 256;

    private const DISK_DIR = 'clan-logos';

    public static function store(Clan $clan, UploadedFile $file): void
    {
        $source = self::loadImage($file);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagepng($resized);
        $contents = ob_get_clean();
        imagedestroy($resized);

        // Mismo orden que PlayerIcon::store() -- escribir primero, solo
        // actualizar la columna si Storage::put() confirma exito (put()
        // devuelve false en un fallo en vez de lanzar, ver esa clase).
        $path = self::DISK_DIR.'/'.$clan->id.'.png';
        $written = Storage::disk('public')->put($path, $contents);

        if (! $written) {
            throw new RuntimeException('No se pudo guardar el logo en el servidor.');
        }

        $clan->update(['logo_path' => $path]);
    }

    public static function destroy(Clan $clan): void
    {
        if ($clan->logo_path) {
            Storage::disk('public')->delete($clan->logo_path);
        }

        $clan->update(['logo_path' => null]);
    }

    private static function loadImage(UploadedFile $file)
    {
        $contents = file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new RuntimeException('No se pudo leer la imagen subida.');
        }

        return $image;
    }
}
