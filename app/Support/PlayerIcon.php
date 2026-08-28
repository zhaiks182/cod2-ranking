<?php

namespace App\Support;

use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Icono personalizado por jugador (2026-08-28, /adm_cod2/jugadores/iconos) --
 * generaliza el chiste hardcodeado de dtN.harek (guid 1127155189, un burro al
 * lado de su medalla en el top 3, ver leaderboard.blade.php/dashboard.blade.php
 * antes de este cambio) a cualquier jugador, subido por el admin.
 *
 * "El icono siempre debe ajustarse" (pedido del dueño): un admin puede subir
 * cualquier imagen (una foto de varios MB, una relacion de aspecto rara) y no
 * hay forma de confiar en que venga ya lista para mostrarse chica al lado de
 * una medalla -- se re-escala server-side con GD ANTES de guardar, no se
 * confia en CSS solo para achicar el archivo original. burro.png (la
 * referencia real) es 294x512, no cuadrado, y se muestra con
 * `width:11px;height:auto` -- por eso acá se re-escala manteniendo la
 * relacion de aspecto (fit-within, nunca crop a cuadrado ni upscale) en vez
 * de forzar un cuadrado que el propio ejemplo real no usa.
 */
class PlayerIcon
{
    private const MAX_DIMENSION = 128;

    private const DISK_DIR = 'player-icons';

    public static function store(Player $player, UploadedFile $file): void
    {
        $source = self::loadImage($file);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        // Preserva transparencia (el burro de referencia es un PNG con alpha) --
        // sin esto, cualquier area transparente del original se rellena de negro.
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

        // El path es siempre "{player_id}.png" (nombre fijo) -- guardar acá
        // pisa directo cualquier icono anterior de este jugador, no hace falta
        // borrar uno viejo antes. Importante hacerlo en este orden: escribir
        // PRIMERO y solo actualizar la columna si Storage::put() confirma que
        // el archivo quedo escrito -- put() devuelve false en un fallo (permisos,
        // disco lleno) en vez de lanzar una excepcion, y antes este metodo
        // ignoraba ese valor de retorno: la fila quedaba con icon_path apuntando
        // a un archivo que nunca se creo, mostrando un icono roto en el sitio en
        // vez de un error visible para el admin (encontrado en vivo 2026-08-28,
        // con la subida real de un jugador -- el directorio player-icons habia
        // quedado con permisos de root de una migracion anterior corrida por
        // SSH, asi que www-data no podia escribir ahi y el fallo pasaba
        // desapercibido).
        $path = self::DISK_DIR.'/'.$player->id.'.png';
        $written = Storage::disk('public')->put($path, $contents);

        if (! $written) {
            throw new RuntimeException('No se pudo guardar el ícono en el servidor (revisar permisos de storage/app/public/player-icons).');
        }

        $player->update(['icon_path' => $path]);
    }

    public static function destroy(Player $player): void
    {
        if ($player->icon_path) {
            Storage::disk('public')->delete($player->icon_path);
        }

        $player->update(['icon_path' => null]);
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
