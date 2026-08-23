<?php

namespace App\Console\Commands;

use App\Models\HostedServer;
use App\Support\HostedServerProvisioner;
use Illuminate\Console\Command;

class ExpireHostedServers extends Command
{
    protected $signature = 'hosted-servers:expire';

    protected $description = 'Apaga y limpia instancias temporales vencidas, vacias hace rato, o trabadas en "starting"';

    public function handle(HostedServerProvisioner $provisioner): void
    {
        $idleCutoff = now()->subMinutes((int) config('hosted_servers.idle_minutes'));
        $stuckCutoff = now()->subMinutes(2);

        $expired = HostedServer::where('status', 'running')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $server) {
            $provisioner->stop($server, 'expired');
            $this->info("Expirada (tiempo cumplido): #{$server->id}");
        }

        $idle = HostedServer::where('status', 'running')
            ->where('last_seen_players_at', '<', $idleCutoff)
            ->get();

        foreach ($idle as $server) {
            $provisioner->stop($server, 'expired');
            $this->info("Expirada (vacia hace mas de ".config('hosted_servers.idle_minutes')." min): #{$server->id}");
        }

        // Barrido de "provisioning que murio a mitad de camino" -- si el proceso PHP
        // que corria HostedServerProvisioner::provision() se corto (deploy, worker
        // reciclado) despues de crear la fila pero antes de terminar, queda una fila
        // en "starting" para siempre, ocupando un puerto y un lugar en el tope de
        // concurrencia sin que nadie la limpie. stop() es seguro de llamar aunque el
        // proceso real nunca haya llegado a arrancar (systemctl stop sobre una unit
        // caida no tira error, ver HostedServerProvisioner::systemctl()).
        $stuck = HostedServer::where('status', 'starting')
            ->where('created_at', '<', $stuckCutoff)
            ->get();

        foreach ($stuck as $server) {
            $provisioner->stop($server, 'failed');
            $this->info("Limpiada (trabada en starting): #{$server->id}");
        }
    }
}
