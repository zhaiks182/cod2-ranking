<?php

namespace App\Support;

use App\Models\SiteUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Foto de perfil autodeclarada por el jugador (2026-09-01, modulo de perfil
 * gaming), subida desde /mi-cuenta -- mismo patron de resize server-side con
 * GD que PlayerIcon (fit-within, nunca crop ni upscale, PNG con alpha
 * preservado), pero de un jugador subiendo SU PROPIA foto en vez de un admin
 * subiendo el icono de otro. 256px (mas grande que el icono de 128px del
 * ranking, esto se muestra como foto de perfil grande en /mi-cuenta).
 */
class SiteUserAvatar
{
    private const MAX_DIMENSION = 256;

    private const DISK_DIR = 'site-user-avatars';

    public static function store(SiteUser $siteUser, UploadedFile $file): void
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

        // Path fijo ("{id}.png") -- una subida nueva pisa la anterior, no hace
        // falta borrar antes. Escribir primero, solo actualizar la columna si
        // Storage::put() confirma exito (mismo orden que PlayerIcon, mismo
        // motivo: un fallo de permisos/disco no debe dejar la fila apuntando
        // a un archivo que nunca se creo).
        $path = self::DISK_DIR.'/'.$siteUser->id.'.png';
        $written = Storage::disk('public')->put($path, $contents);

        if (! $written) {
            throw new RuntimeException('No se pudo guardar la foto de perfil en el servidor.');
        }

        $siteUser->update(['avatar_path' => $path]);
    }

    public static function destroy(SiteUser $siteUser): void
    {
        if ($siteUser->avatar_path) {
            Storage::disk('public')->delete($siteUser->avatar_path);
        }

        $siteUser->update(['avatar_path' => null]);
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
