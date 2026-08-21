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

        return view('help.faq', compact('connectString'));
    }
}
