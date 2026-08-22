<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    private const DIR = 'backups';

    public function index()
    {
        $backups = collect(Storage::disk('local')->files(self::DIR))
            ->map(fn ($path) => (object) [
                'path' => $path,
                'name' => basename($path),
                'size' => Storage::disk('local')->size($path),
                'date' => \Illuminate\Support\Carbon::createFromTimestamp(Storage::disk('local')->lastModified($path)),
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
        if (! preg_match('/^cod2_stats_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/', $filename)) {
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
        if (! preg_match('/^cod2_stats_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/', $filename)) {
            abort(404);
        }

        $path = self::DIR.'/'.$filename;
        Storage::disk('local')->delete($path);

        AdminAction::record('backups.destroy', "Elimino el respaldo ({$filename})");

        return back()->with('status', "Respaldo eliminado ({$filename}).");
    }
}
