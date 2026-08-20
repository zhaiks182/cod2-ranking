<?php

namespace App\Http\Controllers;

use App\Models\Demo;
use App\Models\Player;
use App\Support\DemoMatchResolver;
use App\Support\HwidHasher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class DemoUploadController extends Controller
{
    /**
     * Recibe el .dm_1 que el cliente CoD2x sube automaticamente al terminar una
     * partida SD (ver _record.gsc::execRecording()). El cliente manda el binario
     * crudo en el body (sin multipart, sin Content-Type) y espera 200/201 (o 409 si
     * ya existe) para marcar el upload como terminado; cualquier otro codigo hace
     * que reintente hasta 3 veces.
     */
    public function store(Request $request, string $hwid, string $demoName): Response
    {
        // demoName ya viene filtrado del lado del GSC (getSecureString), pero no nos
        // podemos fiar de un cliente externo — mismo charset permitido alla (sin '#'
        // ni gen-delims de URL, ver el comentario en _record.gsc).
        if (! preg_match('/^[a-zA-Z0-9=\-+_!()]+$/', $demoName)) {
            return response('Invalid demo name', 422);
        }

        $body = $request->getContent();

        if ($body === '' || $body === null) {
            return response('Empty body', 422);
        }

        $relativePath = "demos/{$hwid}/{$demoName}.dm_1";

        if (Storage::disk('local')->exists($relativePath)) {
            return response('Demo already exists', 409);
        }

        Storage::disk('local')->put($relativePath, $body);

        Demo::create([
            'player_id' => Player::where('guid', HwidHasher::hwidToGuid($hwid))->value('id'),
            'match_id' => DemoMatchResolver::resolve(now(), $demoName)?->id,
            'hwid' => $hwid,
            'demo_name' => $demoName,
            'file_path' => $relativePath,
            'size_bytes' => strlen($body),
        ]);

        return response('', 201);
    }
}
