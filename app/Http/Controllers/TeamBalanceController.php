<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Cod2RconClient;
use App\Services\DiscordTeamsNotifier;
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
        $mantenerActive = false;

        if ($server && $status && $request->boolean('generar')) {
            $ranks = PlayerRankCalculator::calculateForServer($server);
            $requestedMantener = $request->has('mantener') ? $request->boolean('mantener') : null;
            $mantenerActive = TeamBalancer::shouldPreserve($requestedMantener, $server);
            $previous = $mantenerActive ? TeamBalancer::previousAssignments($server) : null;
            $teamBalance = TeamBalancer::suggest($status['players'] ?? [], $ranks, $server, $previous);
            TeamBalancer::rememberAssignments($server, $teamBalance);
        }

        return view('team-balance', compact('servers', 'server', 'status', 'teamBalance', 'mantenerActive'));
    }

    /**
     * Boton publico "Notificar Discord" de /equipos (2026-09-01) -- a
     * diferencia de Admin\ConsoleController::notifyTeams(), esta version no
     * pide sesion admin, a pedido explicito del dueño: cualquier jugador que
     * genere el balance puede avisar al canal. Recalcula el balance en el
     * momento del click (RCON en vivo) por el mismo motivo que la version
     * admin -- no confiar en el roster que la pagina tenia renderizado unos
     * segundos antes. Throttle en la ruta (ver routes/web.php) para que no
     * se pueda inundar el canal de Discord sin login de por medio.
     */
    public function notifyDiscord(Request $request)
    {
        $data = $request->validate(['server' => ['required', 'string']]);

        $server = Server::where('is_active', true)->where('slug', $data['server'])->first();
        if (! $server) {
            return back()->with('error', 'Servidor no encontrado.');
        }

        $status = Cod2RconClient::forServer($server)->status();
        if (! $status) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — no se notificó nada.');
        }

        $requestedMantener = $request->has('mantener') ? $request->boolean('mantener') : null;
        $previous = TeamBalancer::shouldPreserve($requestedMantener, $server) ? TeamBalancer::previousAssignments($server) : null;
        $teamBalance = TeamBalancer::suggest($status['players'] ?? [], PlayerRankCalculator::calculateForServer($server), $server, $previous);
        if (! $teamBalance->enough) {
            return back()->with('error', 'No hay suficientes jugadores conectados para armar equipos — no se notificó nada.');
        }
        TeamBalancer::rememberAssignments($server, $teamBalance);

        if (! DiscordTeamsNotifier::notify($server, $teamBalance->teamA, $teamBalance->teamB)) {
            return back()->with('error', 'No se pudo postear a Discord — revisá que el webhook de equipos esté configurado.');
        }

        return back()->with('status', 'Equipos notificados a Discord.');
    }
}
