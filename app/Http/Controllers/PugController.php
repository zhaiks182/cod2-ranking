<?php

namespace App\Http\Controllers;

use App\Models\Pug;
use App\Models\Server;
use App\Services\Cod2RconClient;
use App\Services\DiscordPugNotifier;
use App\Support\PlayerRankCalculator;
use App\Support\PugManager;
use App\Support\TeamBalancer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Pugs: abrir una sesion con los equipos congelados, postularse de capitan y
 * vetear mapas. Ver "Modulo de pugs" en CLAUDE.md.
 *
 * Todo cuelga de /equipos -- no hay pagina propia, el veto es el paso 2 del
 * mismo flujo con el que se arman los equipos.
 */
class PugController extends Controller
{
    /**
     * Congela los equipos que hay AHORA y abre el pug. Se recalcula contra RCON en
     * vivo en vez de confiar en el HTML ya renderizado: el roster pudo cambiar
     * entre que se cargo la pagina y que se apreto el boton (mismo criterio que
     * notifyDiscord/rebalance).
     */
    public function start(Request $request)
    {
        $data = $request->validate(['server' => ['required', 'string']]);

        $server = Server::where('is_active', true)->where('slug', $data['server'])->first();

        if (! $server) {
            return back()->with('error', 'Servidor no encontrado.');
        }

        $status = Cod2RconClient::forServer($server)->status();

        if (! $status) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — no se abrió ningún pug.');
        }

        $previous = TeamBalancer::shouldPreserve(null, $server) ? TeamBalancer::previousAssignments($server) : null;
        $teamBalance = TeamBalancer::suggest($status['players'] ?? [], PlayerRankCalculator::calculateForServer($server), $server, $previous);

        try {
            PugManager::start($server, $teamBalance);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pug abierto. Los capitanes ya pueden postularse.');
    }

    public function claimCaptain(Request $request, Pug $pug)
    {
        $data = $request->validate(['team' => ['required', 'in:A,B']]);

        try {
            PugManager::claimCaptain($pug, Auth::guard('site')->user(), $data['team']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Sos capitán del equipo '.$data['team'].'.');
    }

    /**
     * Banea un mapa. Si con este baneo se cierra el veto, dispara los dos efectos
     * automaticos: el anuncio a Discord y la carga del primer mapa por RCON.
     */
    public function ban(Request $request, Pug $pug)
    {
        $data = $request->validate(['map' => ['required', 'string', 'max:64']]);

        try {
            PugManager::ban($pug, Auth::guard('site')->user(), $data['map']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pug->refresh();

        if ($pug->status !== Pug::STATUS_PLAYING) {
            return back()->with('status', 'Mapa baneado.');
        }

        // Ninguno de los dos puede tumbar el veto, que ya quedo guardado: si Discord
        // o RCON fallan se avisa, pero los mapas elegidos siguen firmes.
        $announced = DiscordPugNotifier::notifyMaps($pug);
        $loaded = PugManager::loadCurrentMap($pug);

        $warnings = array_filter([
            $announced ? null : 'no se pudo anunciar en Discord',
            $loaded ? null : 'no se pudo cambiar el mapa por RCON',
        ]);

        return back()->with(
            $warnings ? 'error' : 'status',
            $warnings
                ? 'Veto terminado, pero '.implode(' y ', $warnings).'.'
                : 'Veto terminado. Los mapas se anunciaron en Discord y el servidor ya está cargando el primero.'
        );
    }

    /** Cerrar a mano: solo los capitanes del propio pug. */
    public function close(Pug $pug)
    {
        if (! $pug->captainTeamFor(Auth::guard('site')->user())) {
            return back()->with('error', 'Solo los capitanes pueden cerrar el pug.');
        }

        PugManager::close($pug);

        return back()->with('status', 'Pug cerrado.');
    }
}
