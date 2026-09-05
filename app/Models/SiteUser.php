<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

/**
 * Cuenta publica del sitio (login con Discord) -- guard `site`, separado del
 * guard `web` que usa el panel admin (tabla `users`). Ver "Autenticacion" en
 * docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
 */
class SiteUser extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'discord_id', 'discord_username', 'discord_avatar_url', 'avatar_path', 'role',
        'discord_global_name', 'discord_email', 'discord_email_verified', 'discord_locale',
        'player_id', 'pending_claim_player_id', 'claim_code', 'claim_code_expires_at',
        'bio', 'country', 'language', 'preferred_role',
        'steam_url', 'twitch_url', 'instagram_url', 'youtube_url', 'twitter_url', 'website_url',
        'pc_cpu', 'pc_gpu', 'pc_ram', 'pc_peripherals',
    ];

    protected $casts = [
        'claim_code_expires_at' => 'datetime',
        'discord_email_verified' => 'boolean',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * La foto subida por el jugador (SiteUserAvatar::store()) siempre le gana
     * al avatar de Discord si existe -- es una eleccion explicita del jugador,
     * el de Discord es solo el default hasta que suba una propia.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? Storage::disk('public')->url($this->avatar_path)
            : $this->discord_avatar_url;
    }

    public function pendingClaimPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'pending_claim_player_id');
    }

    public function galleryItems(): HasMany
    {
        return $this->hasMany(GalleryItem::class);
    }

    public function hasPendingClaim(): bool
    {
        return $this->pending_claim_player_id !== null
            && $this->claim_code_expires_at !== null
            && $this->claim_code_expires_at->isFuture();
    }

    /** Un jugador solo pertenece a un clan a la vez (unique en clan_members.site_user_id). */
    public function clanMembership(): HasOne
    {
        return $this->hasOne(ClanMember::class);
    }

    public function clan(): ?Clan
    {
        return $this->clanMembership?->clan;
    }

    /** Invitaciones que ESTE jugador recibio de un clan, pendientes de su respuesta. */
    public function receivedClanInvitations(): HasMany
    {
        return $this->hasMany(ClanInvitation::class)->where('direction', 'manager_invited')->where('status', 'pending');
    }
}
