<?php

namespace App\Support;

use App\Models\Pug;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SiteUser;
use App\Services\Cod2RconClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operaciones de un pug: abrirlo con los equipos congelados, postularse de
 * capitan, banear mapas y avanzar la lista. Ver "Modulo de pugs" en CLAUDE.md.
 *
 * Todo lo que puede fallar por una accion invalida del usuario tira
 * RuntimeException con un mensaje mostrable; el controller lo convierte en un
 * error de formulario (mismo patron que PlayerIcon::store()).
 */
class PugManager
{
    /**
     * Congela los equipos que armo TeamBalancer y abre la sesion. El snapshot es
     * una COPIA a proposito: si alguien regenera equipos despues, el pug ya
     * empezado no cambia de composicion.
     */
    public static function start(Server $server, object $teamBalance): Pug
    {
        if (Pug::openFor($server->id)) {
            throw new RuntimeException('Ya hay un pug abierto en este servidor.');
        }

        if (! ($teamBalance->enough ?? false)) {
            throw new RuntimeException('No hay suficientes jugadores conectados para armar un pug.');
        }

        return Pug::create([
            'server_id' => $server->id,
            'status' => Pug::STATUS_AWAITING_CAPTAINS,
            'teams' => [
                'A' => self::snapshot($teamBalance->teamA),
                'B' => self::snapshot($teamBalance->teamB),
            ],
            'started_at' => now(),
        ]);
    }

    /** @return array<int, array{guid: int, name: string}> */
    private static function snapshot(iterable $team): array
    {
        $players = [];

        foreach ($team as $player) {
            $players[] = ['guid' => (int) $player->guid, 'name' => (string) $player->name];
        }

        return $players;
    }

    /**
     * El primero de cada equipo que reclama el rol se lo queda. Cuando estan los
     * dos, arranca el veto: se copia el pool vigente y se sortea quien banea
     * primero.
     */
    public static function claimCaptain(Pug $pug, SiteUser $siteUser, string $team): void
    {
        if ($pug->status !== Pug::STATUS_AWAITING_CAPTAINS) {
            throw new RuntimeException('Este pug ya tiene sus dos capitanes.');
        }

        if (! in_array($team, ['A', 'B'], true)) {
            throw new RuntimeException('Equipo inválido.');
        }

        if ($pug->captainTeamFor($siteUser)) {
            throw new RuntimeException('Ya sos capitán de este pug.');
        }

        if (! $siteUser->player_id) {
            throw new RuntimeException('Necesitás reclamar tu perfil de jugador antes de ser capitán.');
        }

        if (! $pug->siteUserPlaysIn($siteUser, $team)) {
            throw new RuntimeException('Solo podés ser capitán del equipo en el que estás jugando.');
        }

        $column = $team === 'A' ? 'team_a_captain_site_user_id' : 'team_b_captain_site_user_id';

        // Bloqueo por fila: dos jugadores del mismo equipo tocando el boton a la vez
        // no pueden quedar los dos de capitan.
        DB::transaction(function () use ($pug, $column, $siteUser) {
            $fresh = Pug::whereKey($pug->id)->lockForUpdate()->first();

            if ($fresh->{$column} !== null) {
                throw new RuntimeException('Ese equipo ya tiene capitán.');
            }

            $fresh->{$column} = $siteUser->id;

            if ($fresh->team_a_captain_site_user_id && $fresh->team_b_captain_site_user_id) {
                $fresh->status = Pug::STATUS_VETO;
                $fresh->veto_pool = self::pool();
                $fresh->veto_bans = [];
                $fresh->first_turn_team = random_int(0, 1) === 0 ? 'A' : 'B';
            }

            $fresh->save();
        });

        $pug->refresh();
    }

    /**
     * El pool configurado por el admin. Si nunca se configuro, cae a los 4 mapas
     * que la comunidad realmente juega (los mismos que MapCatalog prioriza) en vez
     * de meter los 15 al veto.
     *
     * @return array<int, string>
     */
    public static function pool(): array
    {
        $configured = Setting::current()->pug_veto_pool ?? [];

        $valid = array_values(array_intersect($configured, array_keys(MapCatalog::pickerOptions())));

        return $valid ?: ['mp_toujane_fix', 'mp_dawnville_fix', 'mp_burgundy_fix', 'mp_railyard'];
    }

    /**
     * Banea un mapa. Cuando quedan exactamente los mapas que se van a jugar, cierra
     * el veto y deja la lista ordenada -- el aviso a Discord y el cambio de mapa
     * los dispara el controller/comando, no esta clase.
     */
    public static function ban(Pug $pug, SiteUser $siteUser, string $map): void
    {
        if ($pug->status !== Pug::STATUS_VETO) {
            throw new RuntimeException('Este pug no está en fase de veto.');
        }

        $team = $pug->captainTeamFor($siteUser);

        if (! $team) {
            throw new RuntimeException('Solo los capitanes pueden banear mapas.');
        }

        if ($pug->currentTurnTeam() !== $team) {
            throw new RuntimeException('No es tu turno.');
        }

        if (! in_array($map, $pug->remainingMaps(), true)) {
            throw new RuntimeException('Ese mapa no está disponible para banear.');
        }

        $bans = $pug->veto_bans ?? [];
        $bans[] = ['map' => $map, 'team' => $team, 'at' => now()->toIso8601String()];
        $pug->veto_bans = $bans;

        if ($pug->vetoIsComplete()) {
            $pug->maps = $pug->remainingMaps();
            $pug->status = Pug::STATUS_PLAYING;
            $pug->current_map_index = 0;
        }

        $pug->save();
    }

    /**
     * Manda el mapa que corresponde ahora al servidor por RCON. Devuelve false si
     * no se pudo (server caido, sin mapa) -- nunca tira, porque esto corre tanto
     * desde una accion del usuario como desde el scheduler.
     */
    public static function loadCurrentMap(Pug $pug): bool
    {
        $map = $pug->currentMap();

        if (! $map || ! $pug->server) {
            return false;
        }

        $client = Cod2RconClient::forServer($pug->server);

        if (! $client->status()) {
            return false;
        }

        $client->command('map '.$map);

        return true;
    }

    /** Pasa al proximo mapa de la lista y lo carga. False si ya era el ultimo. */
    public static function advanceToNextMap(Pug $pug): bool
    {
        if ($pug->nextMap() === null) {
            return false;
        }

        $pug->current_map_index++;
        $pug->save();

        return self::loadCurrentMap($pug);
    }

    public static function close(Pug $pug): void
    {
        $pug->update(['status' => Pug::STATUS_CLOSED, 'ended_at' => now()]);
    }
}
