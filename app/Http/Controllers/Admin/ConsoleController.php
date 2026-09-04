<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Ban;
use App\Models\Player;
use App\Models\Server;
use App\Models\ServerResourceSample;
use App\Services\Cod2RconClient;
use App\Services\DiscordTeamsNotifier;
use App\Support\PlayerRankCalculator;
use App\Support\TeamBalancer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Process\Process;

class ConsoleController extends Controller
{
    public function show(Request $request, Server $server)
    {
        $status = Cod2RconClient::forServer($server)->status();

        // Sugerencia de equipos balanceados por rango (PlayerRankCalculator,
        // el mismo score de /especialidades/rangos) -- solo se calcula si el
        // server respondio por RCON, ya que depende de la lista de
        // conectados. Ver TeamBalancer para el porque del snake draft y por
        // que solo sugiere en vez de mover jugadores por RCON.
        $teamBalance = null;
        if ($status) {
            $previous = $request->boolean('mantener') ? TeamBalancer::previousAssignments($server) : null;
            $teamBalance = TeamBalancer::suggest($status['players'] ?? [], PlayerRankCalculator::calculateForServer($server), $server, $previous);
            TeamBalancer::rememberAssignments($server, $teamBalance);
        }

        return view('admin.console', compact('server', 'status', 'teamBalance'));
    }

    /**
     * Boton "Notificar Discord" del balanceo sugerido (2026-09-01) --
     * termina de conectar DiscordTeamsNotifier, que ya existia (servicio +
     * test) pero nunca habia quedado disparable desde el sitio, ver
     * CLAUDE.md. Recalcula el balance en el momento del click (RCON en
     * vivo, no confia en lo que la pagina tenia renderizado unos segundos
     * antes) para no notificar un roster que ya cambio.
     */
    public function notifyTeams(Request $request, Server $server)
    {
        $status = Cod2RconClient::forServer($server)->status();

        if (! $status) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — no se notificó nada.');
        }

        $previous = $request->boolean('mantener') ? TeamBalancer::previousAssignments($server) : null;
        $teamBalance = TeamBalancer::suggest($status['players'] ?? [], PlayerRankCalculator::calculateForServer($server), $server, $previous);

        if (! $teamBalance->enough) {
            return back()->with('error', 'No hay suficientes jugadores conectados para armar equipos — no se notificó nada.');
        }
        TeamBalancer::rememberAssignments($server, $teamBalance);

        if (! DiscordTeamsNotifier::notify($server, $teamBalance->teamA, $teamBalance->teamB)) {
            return back()->with('error', 'No se pudo postear a Discord — revisá que el webhook de equipos esté configurado en /adm_cod2/discord.');
        }

        AdminAction::record('console.notify-teams', "Notificó los equipos armados de {$server->name} a Discord");

        return back()->with('status', 'Equipos notificados a Discord.');
    }

    /**
     * Pagina propia para CPU/RAM del gameserver -- separada de la consola a
     * pedido del dueño (2026-08-20), para que "Consola" sea solo del juego
     * (jugadores, RCON, mapa) y "Recursos" sea solo del sistema. Antes vivia
     * como una seccion mas dentro de admin.console.
     */
    public function resources(Server $server)
    {
        $resourceSamples = $this->fetchResourceSamples($server);

        return view('admin.resources', compact('server', 'resourceSamples'));
    }

    /**
     * Fragmento HTML del widget de recursos solo, para el polling desde JS
     * (mismo patron que dashboard.live-status en el home) -- el panel se
     * actualiza solo, sin F5.
     */
    public function resourceUsage(Server $server)
    {
        $resourceSamples = $this->fetchResourceSamples($server);

        return view('partials.resource-usage', compact('server', 'resourceSamples'));
    }

    /**
     * Ultimas 48h de muestras de cod2:sample-resources (una por minuto).
     * Vacio si el servidor no tiene systemd_service configurado -- el
     * comando que las genera se salta esos servidores. Debe coincidir con
     * la retencion de cod2:prune-resource-samples (tambien 48h) -- si se
     * cambia uno hay que cambiar el otro, sino se pediria una ventana mas
     * larga de la que efectivamente se conserva en la BD.
     */
    private function fetchResourceSamples(Server $server)
    {
        return ServerResourceSample::where('server_id', $server->id)
            ->where('sampled_at', '>=', now()->subDays(2))
            ->orderBy('sampled_at')
            ->get(['cpu_percent', 'memory_bytes', 'swap_bytes', 'sampled_at']);
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

        AdminAction::record('console.kick', "Expulso al slot {$data['slot']} en {$server->name}");

        return back()->with('status', 'Jugador expulsado.');
    }

    /**
     * banClient es un comando nativo del engine de CoD2 (no de zPAM ni CoD2x) --
     * escribe el guid en ban.txt en el gameserver y el motor mismo rechaza esa
     * conexion en el futuro (SV_IsBannedGuid, se ve en SV_DirectConnect). No hace
     * falta ningun cambio de mod para esto, es el mismo mecanismo que kick pero
     * persistente. El guid viene de status() (ya lo parsea Cod2RconClient), asi
     * que coincide exacto con players.guid sin ninguna conversion extra.
     */
    public function ban(Request $request, Server $server)
    {
        $data = $request->validate([
            'slot' => ['required', 'integer'],
            'guid' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $client = Cod2RconClient::forServer($server);
        if (! $this->isReachable($client)) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — el jugador probablemente NO fue baneado.');
        }

        $client->command('banClient '.$data['slot']);
        usleep(300000);

        $ban = Ban::create([
            'player_id' => Player::where('guid', $data['guid'])->value('id'),
            'guid' => $data['guid'],
            'player_name' => $data['name'],
            'reason' => $data['reason'] ?: null,
            'banned_by' => Auth::id(),
        ]);

        AdminAction::record('console.ban', "Baneo a {$data['name']} (guid {$data['guid']}) en {$server->name}".($data['reason'] ? " — {$data['reason']}" : ''));

        return back()->with('status', "Jugador baneado ({$data['name']}).");
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
            AdminAction::record('console.message', "Mensaje a todos en {$server->name}: {$text}");
        } else {
            $admin = Auth::user()->name;
            $client->command('tell '.$data['slot'].' "^6'.$admin.' (Privado): ^7'.$text.'"');
            AdminAction::record('console.message', "Mensaje privado al slot {$data['slot']} en {$server->name}: {$text}");
        }
        usleep(300000);

        return back()->with('status', 'Mensaje enviado.');
    }

    /**
     * Revertido a solo cambiar mapa (2026-08-18) — se probo agregar g_gametype
     * al combo y un cambio de mapa/gametype combinado aislado funcionaba bien,
     * pero en pruebas repetidas seguidas el server dejo de aplicar los cambios
     * (posible proteccion anti-spam de "map"/cvars mas estricta de lo que
     * sv_floodProtect hace para comandos sueltos como kick/say, sin confirmar
     * del todo). El dueño pidio volver a la version simple mientras tanto:
     * solo `map <codigo>`, sin tocar g_gametype.
     */
    public function changeMap(Request $request, Server $server)
    {
        $data = $request->validate(['map' => ['required', 'string', 'max:64']]);

        $client = Cod2RconClient::forServer($server);
        if (! $this->isReachable($client)) {
            return back()->with('error', 'No se pudo conectar al servidor por RCON — el mapa probablemente NO cambió.');
        }

        $client->command('map '.$data['map']);
        usleep(300000);

        AdminAction::record('console.map', "Cambio el mapa a {$data['map']} en {$server->name}");

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

        AdminAction::record('console.command', "Ejecuto comando RCON en {$server->name}: {$data['cmd']}");

        return back()->with('lastCommand', $data['cmd'])->with('lastResult', trim($result));
    }

    /**
     * Control real del proceso (no un simple map_restart por RCON) — reiniciar,
     * detener o iniciar el servicio systemd del gameserver, pedido explicito del
     * dueño (2026-08-19), sabiendo que corta a todos los jugadores conectados.
     * www-data no tenia sudo antes de esto; se agrego una regla acotada en
     * /etc/sudoers.d/cod2-panel que permite EXACTAMENTE estas tres combinaciones
     * de "systemctl <accion> cod2server.service" y nada mas (ver CLAUDE.md). El
     * nombre del servicio sale de servers.systemd_service, no del request, y la
     * accion esta restringida a la misma whitelist que el sudoers, asi que no hay
     * forma de que esto ejecute un comando arbitrario.
     */
    public function service(Request $request, Server $server)
    {
        $data = $request->validate(['action' => ['required', 'in:restart,stop,start']]);
        $action = $data['action'];

        if (! $server->systemd_service || ! preg_match('/^[a-zA-Z0-9_.-]+\.service$/', $server->systemd_service)) {
            return back()->with('error', 'Este servidor no tiene un servicio systemd configurado.');
        }

        $process = new Process(['sudo', 'systemctl', $action, $server->systemd_service]);
        $process->run();

        AdminAction::record('console.'.$action, ucfirst($action)." el servicio {$server->systemd_service} en {$server->name}");

        $labels = ['restart' => 'reinicio', 'stop' => 'detencion', 'start' => 'inicio'];

        if (! $process->isSuccessful()) {
            return back()->with('error', "Fallo el {$labels[$action]}: ".trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $messages = [
            'restart' => 'Servicio reiniciado. Puede tardar unos segundos en volver a responder.',
            'stop' => 'Servicio detenido.',
            'start' => 'Servicio iniciado. Puede tardar unos segundos en responder.',
        ];

        return back()->with('status', $messages[$action]);
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

        // Mismo problema que el chat en ParseCod2Log::toUtf8() — el cliente manda
        // acentos en Windows-1252, no UTF-8 (confirmado: "a" con tilde llega como el
        // byte suelto 0xE1). response()->json() usa json_encode(), que lanza
        // "Malformed UTF-8 characters" y tira 500 en CUALQUIER poll que incluya una
        // linea de chat con acento — por eso la consola en vivo nunca mostraba nada
        // apenas alguien escribia con tildes (confirmado en storage/logs/laravel.log,
        // errores repetidos de logTail() con exactamente ese mensaje). Se convierte
        // linea por linea, no el chunk entero, para no arruinar otras lineas del
        // mismo chunk que ya vengan en UTF-8 valido.
        $lines = array_map(
            fn ($line) => mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'Windows-1252'),
            $lines
        );

        return response()->json(['lines' => $lines, 'offset' => $newOffset]);
    }
}
