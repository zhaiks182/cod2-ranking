<?php

namespace App\Http\Requests;

use App\Models\HostedServer;
use App\Support\MapCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHostedServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Exactamente los mismos codigos que ofrece el picker (MapCatalog::pickerOptions(),
        // un codigo por mapa real, ver ese metodo) -- no se acepta un string libre para
        // el mapa: es el unico campo que si se dejara libre podria hacer que el `+map`
        // falle o (peor, si algun dia se deja de validar) se use para inyectar algo en
        // el cfg.
        $validMaps = array_keys(MapCatalog::pickerOptions());

        return [
            // max:20 (NAME_MAX_LENGTH), no 32 -- deja lugar para el " @ Pug Latam" que
            // el controller pega siempre al final (ver HostedServer::NAME_SUFFIX).
            'hostname' => ['required', 'string', 'max:'.HostedServer::NAME_MAX_LENGTH],
            'slots' => ['required', 'integer', 'between:'.config('hosted_servers.slots_min').','.config('hosted_servers.slots_max')],
            'map' => ['required', 'string', Rule::in($validMaps)],
            'join_password' => ['nullable', 'string', 'max:32'],
            'cracked' => ['nullable', 'boolean'],
            // Honeypot: un campo que ningun visitante real llena porque ni lo ve
            // (oculto por CSS en la vista) -- si viene con algo, es un bot rellenando
            // el formulario a ciegas. 'prohibited' tira un error de validacion normal,
            // no hace falta logica especial en el controller.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'slots.between' => 'La cantidad de jugadores debe estar entre :min y :max.',
            'map.in' => 'Ese mapa no está disponible.',
        ];
    }
}
