<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['demo_retention_days'];

    /**
     * Configuracion global del sitio -- siempre una sola fila (id=1). firstOrCreate
     * en vez de find(1) para no romper si alguna vez se pierde la fila semilla.
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
