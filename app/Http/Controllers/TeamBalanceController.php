<?php

namespace App\Http\Controllers;

use App\Models\Pug;
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

            // Reaplica los badges "↔ cambió de equipo" de un rebalanceo
            // recién hecho -- el POST de rebalance() flashea los guids
            // movidos y redirige acá (PRG), pero suggest() nunca setea
            // ->moved, así que hay que reaplicarlo del lado de la vista.
            if (session()->has('team_balance_moved_guids')) {
                TeamBalancer::markMoved($teamBalance, session('team_balance_moved_guids'));
            }
        }

        // El pug abierto de este servidor, si hay -- es lo que convierte /equipos en
        // el paso 1 de una sesion en vez de una sugerencia suelta.
        $pug = $server ? Pug::openFor($server->id) : null;

        return view('team-balance', compact('servers', 'server', 'status', 'teamBalance', 'mantenerActive', 'pug'));
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

    /**
     * Boton publico "Rebalancear equipos" (2026-09-04, ver
     * docs/superpowers/specs/2026-09-04-rebalanceo-minimo-equipos-design.md)
     * -- a diferencia de "mantener asignaciones anteriores" (que nunca
     * mueve a un jugador ya asignado), esto SI puede moverlos, pero busca
     * la combinacion que mueva a la menor cantidad posible. Accion
     * separada y explicita, no cambia el comportamiento del candado.
     */
    public function rebalance(Request $request)
    {
        $data = $request->validate(['server' => ['required', 'string']]);

        $server = Server::where('is_active', true)->where('slug', $data['server'])->first();
        if (! $server) {
            return back()->with('error', 'Servidor no encontrado.');
        }

        $status = Cod2RconClient::forServer($server)->status();
        if (! $status) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — no se rebalanceó nada.');
        }

        $previous = TeamBalancer::previousAssignments($server) ?? [];
        $teamBalance = TeamBalancer::rebalance($status['players'] ?? [], PlayerRankCalculator::calculateForServer($server), $server, $previous);
        if (! $teamBalance->enough) {
            return back()->with('error', 'No hay suficientes jugadores conectados para rebalancear.');
        }
        TeamBalancer::rememberAssignments($server, $teamBalance);

        // Flasheado para que el redirect (PRG) a index() pueda mostrar los
        // badges de quién se movió y el aviso si no se llegó al umbral --
        // ver TeamBalancer::markMoved().
        session()->flash('team_balance_moved_guids', $teamBalance->teamA->concat($teamBalance->teamB)->filter(fn ($p) => $p->moved)->pluck('guid')->all());
        session()->flash('team_balance_met_threshold', $teamBalance->metThreshold);
        session()->flash('team_balance_diff', $teamBalance->diff);

        return redirect()->route('team-balance', ['server' => $server->slug, 'generar' => 1]);
    }
}
