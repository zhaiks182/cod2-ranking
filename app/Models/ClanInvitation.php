<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cubre las dos direcciones de union a un clan con una sola tabla (2026-09-03):
 * `player_requested` (el jugador pide entrar, un Manager/Fundador del clan
 * resuelve) o `manager_invited` (el clan invita a un jugador puntual, el
 * jugador resuelve). Ver docs/superpowers/specs/2026-09-03-clanes-design.md.
 */
class ClanInvitation extends Model
{
    protected $fillable = ['clan_id', 'site_user_id', 'created_by_site_user_id', 'direction', 'status'];

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function siteUser(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'created_by_site_user_id');
    }

    public function isPlayerRequest(): bool
    {
        return $this->direction === 'player_requested';
    }

    public function isManagerInvite(): bool
    {
        return $this->direction === 'manager_invited';
    }
}
