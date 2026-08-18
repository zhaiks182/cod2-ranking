<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Cod2RconClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConsoleController extends Controller
{
    public function show(Server $server)
    {
        $status = Cod2RconClient::forServer($server)->status();

        return view('admin.console', compact('server', 'status'));
    }

    public function kick(Request $request, Server $server)
    {
        $data = $request->validate(['slot' => ['required', 'integer']]);

        $client = Cod2RconClient::forServer($server);
        if (! $this->isReachable($client)) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — el jugador probablemente NO fue expulsado.');
        }

        $client->command('clientkick '.$data['slot']);
        usleep(300000);

        return back()->with('status', 'Jugador expulsado.');
    }

    public function message(Request $request, Server $server)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:all,private'],
            'slot' => ['nullable', 'integer'],
            'text' => ['required', 'string', 'max:200'],
        ]);

        $client = Cod2RconClient::forServer($server);
        if (! $this->isReachable($client)) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — el mensaje probablemente NO se envió.');
        }

        $text = str_replace('"', '', $data['text']);

        if ($data['mode'] === 'all') {
            $client->command('say "^7'.$text.'"');
        } else {
            $admin = Auth::user()->name;
            $client->command('tell '.$data['slot'].' "^6'.$admin.' (Privado): ^7'.$text.'"');
        }
        usleep(300000);

        return back()->with('status', 'Mensaje enviado.');
    }

    public function changeMap(Request $request, Server $server)
    {
        $data = $request->validate(['map' => ['required', 'string', 'max:64']]);

        $client = Cod2RconClient::forServer($server);
        if (! $this->isReachable($client)) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — el mapa probablemente NO cambió.');
        }

        $client->command('map '.$data['map']);
        usleep(300000);

        // El cambio de mapa en si (cargar assets, reconectar jugadores) tarda mucho
        // mas que los 300ms de espera anti flood-protect de arriba — la consulta
        // status() que show() hace en el reload inmediato de esta misma request
        // case casi siempre falla porque el server todavia esta transicionando, no
        // porque la conexion RCON este realmente caida. 'mapChanging' le dice a la
        // vista que muestre un aviso neutral en vez del error rojo de "no se pudo
        // conectar", que quedaba contradictorio al lado del mensaje verde de exito.
        return back()->with('status', 'Cambiando de mapa…')->with('mapChanging', true);
    }

    /**
     * say/tell/clientkick/map are fire-and-forget over UDP — the gameserver never
     * prints anything back for them (confirmed, see command()'s $wantResult), so
     * there's no way to directly confirm THEY landed. Probing with status() first
     * (which does get a reply, and already retries once for a dropped packet) is the
     * closest thing to a real reachability check before claiming success — better
     * than always saying "enviado" even when RCON was actually down the whole time.
     *
     * The 300ms pause after a successful check is load-bearing, not decorative: this
     * server runs sv_floodProtect, and confirmed via direct testing that two rcon
     * packets fired back-to-back (as isReachable()'s status() probe + the real
     * command were) get the second one silently dropped by the engine's own
     * flood-protection window — isReachable() would report "reachable" and the UI
     * would say "enviado", but the actual kick/message/map command never landed.
     * Confirmed directly via tinker: back-to-back with no delay, the second
     * command's reply came back empty; with 300ms in between, both came back
     * with real content.
     *
     * A second 300ms pause is also needed AFTER the real command (see kick/
     * message/changeMap below) for the same reason, one packet later: the
     * redirect this controller returns makes the browser immediately reload the
     * console page, and show()'s own status() call for that reload is close
     * enough to the just-sent command to get flood-protected too — without that
     * second pause, the page reloads into a false "No se pudo conectar por RCON"
     * even though the action itself went through fine.
     */
    private function isReachable(Cod2RconClient $client): bool
    {
        $reachable = $client->status() !== null;

        if ($reachable) {
            usleep(300000);
        }

        return $reachable;
    }

    public function command(Request $request, Server $server)
    {
        $data = $request->validate(['cmd' => ['required', 'string', 'max:500']]);

        $result = Cod2RconClient::forServer($server)->command($data['cmd'], true);

        return back()->with('lastCommand', $data['cmd'])->with('lastResult', trim($result));
    }

    /**
     * Raw tail of the gameserver's log file, for the "live console" panel — polled
     * from the browser every few seconds. Separate from cod2:parse-log's own
     * byte-offset tracking (log_parser_state): that one drives the DB, this one is
     * purely for the admin to *watch* the log, so it keeps its own client-supplied
     * cursor instead of touching parser state or requiring the parser to have run.
     */
    public function logTail(Request $request, Server $server)
    {
        $data = $request->validate(['offset' => ['nullable', 'integer', 'min:0']]);

        if (! $server->log_path || ! is_file($server->log_path)) {
            return response()->json(['lines' => ['(log no encontrado)'], 'offset' => 0]);
        }

        $size = filesize($server->log_path);
        $offset = $data['offset'] ?? null;

        // No cursor yet (first load) or the file shrank/rotated since the client's
        // last known offset — start from a recent window instead of either dumping
        // the whole multi-MB history or reading from a byte offset that no longer
        // exists in the (now-smaller) file.
        if ($offset === null || $offset > $size) {
            $offset = max(0, $size - 16384);
        }

        $handle = fopen($server->log_path, 'rb');
        fseek($handle, $offset);
        $chunk = stream_get_contents($handle);
        $newOffset = ftell($handle);
        fclose($handle);

        $lines = $chunk === '' ? [] : preg_split('/\r?\n/', rtrim($chunk, "\r\n"));

        return response()->json(['lines' => $lines, 'offset' => $newOffset]);
    }
}
