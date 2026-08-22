<?php

namespace App\Http\Controllers;

use App\Models\Server;

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
        return view('help.downloads');
    }
}
