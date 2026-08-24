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
        'hosted_servers_max_concurrent',
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
     * Limite de servidores temporales concurrentes -- pisa el default de
     * config/hosted_servers.php (env HOSTED_SERVERS_MAX_CONCURRENT) en cuanto el
     * admin lo edita desde adm_cod2/servers. Sin tope contra la cantidad real de
     * puertos abiertos en el firewall a proposito (2026-08-24, pedido explicito
     * del dueño) -- si se sube por encima de los puertos realmente disponibles,
     * la creacion de un servidor temporal falla con un error generico
     * ("No se pudo crear el servidor ahora mismo") en vez de romper, pero no
     * hay ninguna advertencia proactiva en la UI.
     */
    public static function maxConcurrent(): int
    {
        return static::current()->hosted_servers_max_concurrent
            ?? (int) config('hosted_servers.max_concurrent');
    }
}
