<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Cod2RconClient;
use App\Support\PlayerRankCalculator;
use App\Support\TeamBalancer;
use Illuminate\Http\Request;

class TeamBalanceController extends Controller
{
    /**
     * Pagina publica dedicada al balanceador de equipos (/equipos, link al
     * final del menu RANKING) -- a diferencia del widget que vivia en la
     * home (sacado, ver CLAUDE.md), esta pagina NO calcula el balance en
     * cada carga. Muestra cuantos jugadores hay conectados ahora mismo
     * (RCON status(), barato) y solo corre PlayerRankCalculator (varias
     * queries sobre el historial completo de partidas del server) cuando el
     * usuario aprieta "Generar equipos" a proposito -- pensado para usarse
     * una vez que todos los jugadores ya estan en el server, no para
     * recalcularse solo cada pocos segundos.
     */
    public function index(Request $request)
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();

        $status = $server ? Cod2RconClient::forServer($server)->status() : null;
        $teamBalance = null;

        if ($server && $status && $request->boolean('generar')) {
            $ranks = PlayerRankCalculator::calculateForServer($server);
            $teamBalance = TeamBalancer::suggest($status['players'] ?? [], $ranks);
        }

        return view('team-balance', compact('servers', 'server', 'status', 'teamBalance'));
    }
}
