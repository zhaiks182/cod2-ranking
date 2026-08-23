<?php

namespace App\Console\Commands;

use App\Models\HostedServer;
use App\Services\Cod2RconClient;
use Illuminate\Console\Command;

class PollHostedServers extends Command
{
    protected $signature = 'hosted-servers:poll';

    protected $description = 'Consulta por RCON la cantidad de jugadores de cada instancia temporal activa, para que hosted-servers:expire sepa cuales estan realmente vacias';

    public function handle(): void
    {
        $servers = HostedServer::where('status', 'running')->get();

        foreach ($servers as $server) {
            $status = (new Cod2RconClient('127.0.0.1', $server->port, $server->rcon_password))->status();

            if ($status === null) {
                // No respondio -- no se toca last_seen_players_at (podria ser un lost
                // packet UDP puntual, no necesariamente que este vacio). Si de verdad
                // esta caido, hosted-servers:expire lo va a agarrar por expires_at
                // igual, y si sigue sin responder en la proxima corrida tampoco se
                // pierde nada por esperar un minuto mas.
                continue;
            }

            $playerCount = count($status['players']);

            $server->update([
                'player_count' => $playerCount,
                'last_seen_players_at' => $playerCount > 0 ? now() : $server->last_seen_players_at,
            ]);
        }
    }
}
