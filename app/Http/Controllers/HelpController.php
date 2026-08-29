<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Support\Facades\File;

class HelpController extends Controller
{
    public function faq()
    {
        $server = Server::where('is_active', true)->orderBy('name')->first();

        $connectString = trim(($server?->connect_ip ?? '').':'.($server?->connect_port ?? ''), ':');
        if ($server?->join_password) {
            $connectString .= '; password '.$server->join_password;
        }

        // Protocolo custom que registra el cliente de CoD2x -- el navegador lo abre
        // como cualquier link externo (steam://, mailto:, etc.), sin necesidad de
        // abrir la consola a mano. Solo ip:puerto, sin password (el juego pide la
        // password aparte si el server la tiene).
        $connectUri = $server ? "cod2://{$server->connect_ip}:{$server->connect_port}" : null;

        return view('help.faq', compact('connectString', 'connectUri'));
    }

    public function downloads()
    {
        // Lista la carpeta real de fast-download del gameserver (la misma que usa
        // sv_wwwBaseURL, ver config/cod2.php) en vez de hardcodear nombres de archivo
        // -- cuando se sube una version nueva del mod/mapas ahi (mismo procedimiento
        // que "Cambios en el mod zPAM" en CLAUDE.md), esta pagina se actualiza sola.
        $path = config('cod2.fast_download_path');

        $mods = collect(File::isDirectory($path) ? File::files($path) : [])
            ->map(fn ($file) => [
                'name' => $file->getFilename(),
                'size_mb' => round($file->getSize() / 1024 / 1024, 1),
                'url' => asset('fastdl/'.rawurlencode($file->getFilename())),
            ])
            ->sortBy('name')
            ->values();

        return view('help.downloads', compact('mods'));
    }
}
