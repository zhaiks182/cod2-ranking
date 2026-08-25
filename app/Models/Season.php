<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = ['name', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * La temporada activa -- exactamente una fila con ended_at NULL en todo
     * momento, garantizado por Admin\SeasonController@store (cierra la vieja y
     * crea la nueva dentro de la misma transaccion, nunca hay una ventana sin
     * ninguna activa).
     */
    public static function current(): self
    {
        return static::whereNull('ended_at')->firstOrFail();
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }
}
