<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AdminAction extends Model
{
    protected $fillable = ['user_id', 'action', 'description'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registro de auditoria para acciones destructivas/operativas del panel admin
     * (borrar partida/demo, kick, mensaje, cambio de mapa, reinicio de servicio,
     * etc). Antes de esto la unica forma de saber "quien borro que" era rastrear
     * el access log de Apache a mano (paso de verdad, 2026-08-19, con partidas y
     * demos borrados durante las pruebas).
     */
    public static function record(string $action, string $description): void
    {
        static::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'description' => $description,
        ]);
    }
}
