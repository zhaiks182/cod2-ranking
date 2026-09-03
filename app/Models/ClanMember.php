<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanMember extends Model
{
    protected $fillable = ['clan_id', 'site_user_id', 'role', 'joined_at'];

    protected $casts = ['joined_at' => 'datetime'];

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function siteUser(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class);
    }

    public function isFounder(): bool
    {
        return $this->role === 'founder';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /** Fundador o Manager -- puede aprobar/invitar/expulsar/editar. */
    public function canManage(): bool
    {
        return $this->role === 'founder' || $this->role === 'manager';
    }
}
