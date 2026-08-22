<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupController extends Controller
{
    private const DIR = 'backups';

    private const FILENAME_PATTERN = '/^cod2_stats_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/';

    public function index()
    {
        $backups = collect(Storage::disk('local')->files(self::DIR))
            ->map(fn ($path) => (object) [
                'path' => $path,
                'name' => basename($path),
                'size' => Storage::disk('local')->size($path),
                // createFromTimestamp() sin segundo argumento devuelve el Carbon en UTC
                // -- el timestamp del filesystem es correcto (epoch, sin zona), pero
                // mostrarlo sin convertir a la zona de la app lo dejaba 5 horas
                // adelantado (mismo bug ya documentado en CLAUDE.md para el timezone
                // de Laravel en general). Confirmado en vivo 2026-08-22: un respaldo
                // creado a las 17:15 local aparecia en la lista como "22:2x".
                'date' => \Illuminate\Support\Carbon::createFromTimestamp(Storage::disk('local')->lastModified($path), config('app.timezone')),
            ])
            ->sortByDesc('date')
            ->values();

        $totalBytes = $backups->sum('size');

        return view('admin.backups.index', compact('backups', 'totalBytes'));
    }

    public function store()
    {
        Artisan::call('backup:run');

        AdminAction::record('backups.create', 'Creo un respaldo manual de la base de datos');

        return back()->with('status', 'Respaldo creado.');
    }

    /**
     * $filename viene del usuario (parte de la URL) -- se valida contra el patron
     * exacto que genera RunDatabaseBackup en vez de confiar en el basename() de
     * Storage para evitar cualquier intento de path traversal.
     */
    public function download(string $filename)
    {
        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            abort(404);
        }

        $path = self::DIR.'/'.$filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $filename)
    {
        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            abort(404);
        }

        $path = self::DIR.'/'.$filename;
        Storage::disk('local')->delete($path);

        AdminAction::record('backups.destroy', "Elimino el respaldo ({$filename})");

        return back()->with('status', "Respaldo eliminado ({$filename}).");
    }

    /**
     * Reemplaza TODA la base de datos actual con el contenido de un respaldo --
     * restaura las 20+ tablas de una (partidas, jugadores, demos, bans,
     * auditoria, settings, todo lo que haya en el dump), no modulo por modulo,
     * porque el dump de mysqldump ya es de la base completa. Antes de tocar
     * nada se toma un respaldo de seguridad del estado actual (por si la
     * restauracion fue un error, queda algo para volver atras).
     */
    public function restore(string $filename)
    {
        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            abort(404);
        }

        $path = self::DIR.'/'.$filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        Artisan::call('backup:run');

        $sql = gzdecode(Storage::disk('local')->get($path));
        $this->importSql($sql);

        AdminAction::record('backups.restore', "Restauro la base de datos desde el respaldo ({$filename})");

        return back()->with('status', "Base de datos restaurada desde {$filename}. Se guardo un respaldo del estado anterior antes de restaurar.");
    }

    /**
     * Para el caso de "instalar el panel en un server nuevo, sin ningun
     * respaldo local todavia" -- subís el .sql/.sql.gz que bajaste con
     * "Descargar" desde el server viejo (o cualquier dump valido de
     * mysqldump) y se importa entero. mysqldump por defecto incluye los
     * CREATE TABLE ademas de los datos, asi que esto funciona aunque la
     * base de datos destino este completamente vacia (recien creada, sin
     * migraciones corridas) -- no hace falta correr `php artisan migrate`
     * antes si el dump ya trae el esquema completo.
     */
    public function import(Request $request)
    {
        // Chequeos manuales (no $request->validate()) para poder mostrar el error
        // con el mismo session('error') que usa el resto del admin -- este layout
        // no renderiza el $errors bag automatico de Laravel en ningun lado.
        if (! $request->hasFile('dump') || ! $request->file('dump')->isValid()) {
            return back()->with('error', 'No se recibió ningún archivo.');
        }

        $file = $request->file('dump');
        $originalName = $file->getClientOriginalName();

        // Tope real de este server: upload_max_filesize/post_max_size en php.ini
        // estan en 25M (confirmado 2026-08-22) -- un dump mas grande que eso ya
        // se corta del lado de PHP antes de llegar aca (esto solo cubre el caso
        // limite de un archivo justo por debajo). Si el dump real llega a pesar
        // mas que esto algun dia, hay que subir esos dos valores en php.ini primero.
        if ($file->getSize() > 25 * 1024 * 1024) {
            return back()->with('error', 'El archivo pesa más de 25 MB (límite de este server).');
        }

        if (! preg_match('/\.(sql|sql\.gz)$/i', $originalName)) {
            return back()->with('error', 'El archivo tiene que ser .sql o .sql.gz.');
        }

        $raw = file_get_contents($file->getRealPath());
        $isGzip = str_ends_with(strtolower($originalName), '.gz');
        $sql = $isGzip ? gzdecode($raw) : $raw;

        if ($sql === false || $sql === '') {
            return back()->with('error', 'No se pudo leer el archivo (¿está corrupto o no es un dump valido?).');
        }

        // Si ya hay algo en la base actual, vale la pena guardarlo antes de
        // pisarlo -- en una instalacion nueva y vacia esto simplemente
        // produce un dump casi vacio, inofensivo.
        Artisan::call('backup:run');

        $this->importSql($sql);

        AdminAction::record('backups.import', "Importo la base de datos desde un archivo subido ({$originalName})");

        return back()->with('status', "Base de datos importada desde {$originalName}. Se guardo un respaldo del estado anterior antes de importar.");
    }

    /** Corre un dump de SQL completo contra la base actual via el cliente `mysql`. */
    private function importSql(string $sql): void
    {
        $config = config('database.connections.'.config('database.default'));

        // La password va en un --defaults-extra-file temporal, no como argumento
        // -p directo -- visible entero via `ps aux` mientras el proceso corre
        // (mismo motivo que en RunDatabaseBackup).
        $credsFile = tempnam(sys_get_temp_dir(), 'db_import_');
        file_put_contents($credsFile, "[client]\nuser={$config['username']}\npassword={$config['password']}\nhost={$config['host']}\nport={$config['port']}\n");
        chmod($credsFile, 0600);

        try {
            $process = new Process([
                'mysql',
                '--defaults-extra-file='.$credsFile,
                $config['database'],
            ]);
            $process->setTimeout(300);
            $process->setInput($sql);
            $process->mustRun();
        } finally {
            @unlink($credsFile);
        }
    }
}
