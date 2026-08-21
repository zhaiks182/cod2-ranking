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
}
