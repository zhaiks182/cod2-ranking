<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Clan real (2026-09-03, ver docs/superpowers/specs/2026-09-03-clanes-design.md)
 * -- identidad + membresia + estadisticas reales de los miembros. Sin
 * ladders/torneos/partidas clan-vs-clan (fuera de alcance explicito).
 */
class Clan extends Model
{
    protected $fillable = ['name', 'tag', 'description', 'founded_on', 'logo_path', 'founder_site_user_id'];

    protected $casts = ['founded_on' => 'date'];

    public function getRouteKeyName(): string
    {
        return 'tag';
    }

    public function members(): HasMany
    {
        return $this->hasMany(ClanMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ClanInvitation::class);
    }

    public function founder(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'founder_site_user_id');
    }

    public function pendingInvitations()
    {
        return $this->invitations()->where('status', 'pending');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
