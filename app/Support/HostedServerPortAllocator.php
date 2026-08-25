<?php

namespace App\Support;

use App\Models\HostedServer;
use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Asigna puerto Y crea la fila en un solo paso -- la seguridad ante dos creaciones
 * simultaneas no viene de un SELECT+lock (un SELECT sobre una tabla con pocas filas
 * activas puede no encontrar nada que lockear y dejar pasar a los dos requests igual),
 * sino de la unique key real en `hosted_servers.port`: se prueba insertar con cada
 * puerto candidato de la lista configurada (Setting::hostedServerPorts()) y, si otro
 * request ya se quedo con ese puerto justo antes, el motor de base de datos rechaza
 * el insert con una unique-constraint exception y se reintenta con el siguiente --
 * atomico de verdad porque lo garantiza el motor de base de datos, no un chequeo
 * previo en PHP que puede quedar desactualizado entre que lee y que escribe.
 */
class HostedServerPortAllocator
{
    /** @throws \RuntimeException si no hay puertos libres en la lista configurada */
    public function allocate(array $attributes): HostedServer
    {
        foreach (Setting::current()->hostedServerPorts() as $port) {
            try {
                // array_merge (no el operador `+`) a proposito: si por algun motivo
                // $attributes ya trajera una clave 'port', el `+` de PHP le da
                // prioridad al array de la IZQUIERDA y hubiera ignorado el puerto
                // candidato en silencio.
                return HostedServer::create(array_merge($attributes, ['port' => $port]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException('No hay puertos disponibles ahora mismo.');
    }
}
