<?php

namespace App\Support;

use App\Models\HostedServer;

/**
 * Arma el directorio y el server.cfg de UNA instancia temporal. Cada instancia tiene
 * su propio directorio chico (fs_homepath) con solo su main/server.cfg -- los mapas,
 * el binario y el .iwd del mod siguen viviendo en la base compartida de produccion
 * (fs_basepath, config('hosted_servers.game_base_dir')), no se duplican.
 *
 * El cfg generado copia el ruleset de zPAM del server.cfg REAL de produccion (mismas
 * reglas de SD que ya estan afinadas) y le pisa abajo, en orden, los cvars propios de
 * la instancia -- CoD2 ejecuta un .cfg linea por linea, asi que un `set` posterior
 * pisa a uno anterior con el mismo cvar. hostname/join_password pasan por
 * HostedServerSanitizer antes de escribirse (ver esa clase) porque terminan crudos
 * dentro de un archivo que el motor interpreta como comandos, no como datos.
 *
 * A proposito NO van por argv/`+set` en el script de lanzamiento: cualquier argumento
 * de linea de comandos queda visible entero para cualquier otro usuario del VPS via
 * `ps aux` mientras el proceso corre -- la misma razon por la que RunDatabaseBackup ya
 * usa un --defaults-extra-file en vez de pasar la password de mysql por CLI (ver ese
 * archivo). rcon_password/g_password son igual de sensibles aca.
 */
class HostedServerConfigWriter
{
    public function write(HostedServer $server): string
    {
        $dir = $this->instanceDir($server);
        $mainDir = $dir.'/main';

        if (! is_dir($mainDir)) {
            mkdir($mainDir, 0755, true);
        }

        file_put_contents($mainDir.'/server.cfg', $this->buildConfig($server));

        // net_port es un cvar "latched" -- tiene que estar disponible ANTES de que el
        // modulo de red se inicialice, asi que no alcanza con un `set` dentro de un
        // .cfg que se +exec-uta despues de que el server ya arranco (mismo motivo por
        // el que start_libcod.sh de produccion lo pasa como +set de linea de comandos,
        // no dentro de server.cfg). El mapa inicial (`+map`) tiene la misma restriccion
        // de timing. Ninguno de los dos es secreto (numero de puerto, codigo de mapa ya
        // validado contra MapCatalog), asi que no hay problema en que el script de
        // arranque los lea de un archivo plano sin credenciales -- start_libcod_temp.sh
        // (ver repo ZPAM COD2) hace `source instance.env` y arma sus propios `+set`.
        file_put_contents($dir.'/instance.env', "PORT={$server->port}\nMAP={$server->map}\n");

        return $dir;
    }

    public function instanceDir(HostedServer $server): string
    {
        return rtrim(config('hosted_servers.base_dir'), '/').'/'.$server->id;
    }

    public function remove(HostedServer $server): void
    {
        $dir = $this->instanceDir($server);

        if (is_dir($dir)) {
            $this->rrmdir($dir);
        }
    }

    private function buildConfig(HostedServer $server): string
    {
        $hostname = HostedServerSanitizer::cfgValue($server->hostname, 32);
        $joinPassword = $server->join_password ? HostedServerSanitizer::cfgValue($server->join_password, 32) : '';
        // Interpolar un bool en un string da "1"/"" (no "0") -- "0" explicito matchea
        // la convencion que ya usa start_libcod.sh de produccion (`cracked="1"`).
        $cracked = $server->cracked ? '1' : '0';

        $baseRuleset = $this->baseRuleset();

        $overrides = <<<CFG

        // --- Instancia temporal #{$server->id} (generado, no editar a mano) ---
        set sv_hostname "{$this->escape($hostname)}"
        set g_password "{$this->escape($joinPassword)}"
        set rcon_password "{$this->escape($server->rcon_password)}"
        set sv_maxclients "{$server->slots}"
        set sv_cracked "{$cracked}"
        // Nunca subir demos de una instancia de prueba al catalogo de produccion --
        // el mod (cargado desde la base compartida) tiene la URL de subida
        // hardcodeada a la produccion real (ver CLAUDE.md, "Subida automatica de
        // demos por HWID"). Sin esto, cualquier partida SD en un server temporal
        // terminaria subiendo demos reales sin partida asociada.
        set scr_recording "0"
        CFG;

        return $baseRuleset."\n".$overrides."\n";
    }

    /**
     * El ruleset de zPAM (todos los scr_sd_*, scr_readyup, limites de armas, MOTD, URL
     * de descarga del mod, etc.) del server.cfg REAL de produccion, sin la seccion de
     * identidad (hostname/passwords) y sin la linea final `map ...` -- esas dos las
     * pone este mismo archivo mas abajo (overrides) y el llamador (`+map <elegido>`),
     * respectivamente. `sv_wwwBaseURL`/`scr_motd` SI se conservan tal cual: no son
     * datos de identidad, son la URL de descarga del mismo mod compartido y un
     * mensaje generico -- deben ser iguales en todas las instancias. Se lee del
     * archivo real en vez de duplicar las ~250 lineas a mano para que un ajuste de
     * reglas en produccion (ej. cambiar scr_sd_end_round) se refleje aca solo sin
     * tener que acordarse de tocar dos archivos.
     */
    private function baseRuleset(): string
    {
        $path = rtrim(config('hosted_servers.game_base_dir'), '/').'/main/server.cfg';

        if (! is_file($path)) {
            // Si el archivo real no esta disponible (ej. corriendo esto en dev sin el
            // VPS montado), no fallar en silencio con un cfg vacio -- mejor un error
            // claro que un server temporal con reglas de gametype rotas.
            throw new \RuntimeException("No se encontro el server.cfg base de produccion en {$path}.");
        }

        $lines = preg_split('/\r?\n/', file_get_contents($path));

        $lines = array_filter($lines, function ($line) {
            $trimmed = trim($line);

            return ! str_starts_with($trimmed, 'set sv_hostname')
                && ! str_starts_with($trimmed, 'set g_password')
                && ! str_starts_with($trimmed, 'set rcon_password')
                && ! str_starts_with($trimmed, 'map ');
        });

        return implode("\n", $lines);
    }

    /** Las comillas ya fueron descartadas por HostedServerSanitizer -- esto es un cinturon y tiradores extra, no la defensa principal. */
    private function escape(string $value): string
    {
        return str_replace('"', '', $value);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
