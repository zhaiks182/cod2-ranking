<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Cuenta publica del sitio (login con Discord) -- guard `site`, separado del
 * guard `web` que usa el panel admin (tabla `users`). Ver "Autenticacion" en
 * docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
 */
class SiteUser extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'discord_id', 'discord_username', 'discord_avatar_url', 'role',
        'player_id', 'pending_claim_player_id', 'claim_code', 'claim_code_expires_at',
        'bio', 'steam_url', 'twitch_url', 'instagram_url',
        'pc_cpu', 'pc_gpu', 'pc_ram', 'pc_peripherals',
    ];

    protected $casts = [
        'claim_code_expires_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function pendingClaimPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'pending_claim_player_id');
    }

    public function hasPendingClaim(): bool
    {
        return $this->pending_claim_player_id !== null
            && $this->claim_code_expires_at !== null
            && $this->claim_code_expires_at->isFuture();
    }
}
