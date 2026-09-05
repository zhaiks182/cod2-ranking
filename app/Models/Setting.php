<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'demo_retention_days',
        'discord_guild_id',
        'discord_invite_url',
        'discord_description',
        'discord_benefits',
        'hosted_servers_ports',
        'discord_match_webhook_url',
        'discord_teams_webhook_url',
        'gallery_quota_mb',
        'gallery_video_max_mb',
        'pug_veto_pool',
        'pug_maps_count',
    ];

    protected $casts = [
        'pug_veto_pool' => 'array',
    ];

    /** @return array<int, string> Un item de beneficio por linea, vacios descartados. */
    public function discordBenefitsList(): array
    {
        return collect(explode("\n", $this->discord_benefits ?? ''))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Configuracion global del sitio -- siempre una sola fila (id=1). firstOrCreate
     * en vez de find(1) para no romper si alguna vez se pierde la fila semilla.
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /**
     * Lista de puertos disponibles para servidores temporales, en el orden en
     * que se editaron desde adm_cod2/servers (HostedServerPortAllocator los
     * prueba en este orden). Si nunca se edito desde el panel, cae al rango
     * consecutivo viejo de config/hosted_servers.php -- mismo fallback que
     * tenia maxConcurrent() antes de este cambio.
     *
     * @return array<int, int>
     */
    public function hostedServerPorts(): array
    {
        if (filled($this->hosted_servers_ports)) {
            return collect(explode(',', $this->hosted_servers_ports))
                ->map(fn ($port) => (int) trim($port))
                ->values()
                ->all();
        }

        $start = (int) config('hosted_servers.port_range_start');
        $count = (int) config('hosted_servers.max_concurrent');

        return range($start, $start + max(1, $count) - 1);
    }

    /**
     * Limite de servidores temporales concurrentes -- ahora es simplemente la
     * cantidad de puertos configurados (hostedServerPorts()), no un numero
     * editado aparte. Antes eran dos valores independientes que podian
     * desincronizarse (subir el limite por encima de los puertos realmente
     * disponibles hacia fallar la creacion con un error generico) -- ver la
     * migracion 2026_08_25_161907 para el detalle completo.
     */
    public static function maxConcurrent(): int
    {
        return count(static::current()->hostedServerPorts());
    }
}
