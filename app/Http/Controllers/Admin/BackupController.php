<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
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

        $config = config('database.connections.'.config('database.default'));

        $credsFile = tempnam(sys_get_temp_dir(), 'db_restore_');
        file_put_contents($credsFile, "[client]\nuser={$config['username']}\npassword={$config['password']}\nhost={$config['host']}\nport={$config['port']}\n");
        chmod($credsFile, 0600);

        try {
            $sql = gzdecode(Storage::disk('local')->get($path));

            $restore = new Process([
                'mysql',
                '--defaults-extra-file='.$credsFile,
                $config['database'],
            ]);
            $restore->setTimeout(300);
            $restore->setInput($sql);
            $restore->mustRun();
        } finally {
            @unlink($credsFile);
        }

        AdminAction::record('backups.restore', "Restauro la base de datos desde el respaldo ({$filename})");

        return back()->with('status', "Base de datos restaurada desde {$filename}. Se guardo un respaldo del estado anterior antes de restaurar.");
    }
}
