<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Extraido de RunDatabaseBackup::prune() (2026-08-27) -- el comando en si no
 * es testeable en el entorno de tests (SQLite, sin mysqldump/mysql real),
 * esta clase si. Dos reglas: borra lo que ya paso la retencion en dias, y de
 * lo que queda, si un mismo dia calendario tiene mas de un respaldo (cron
 * diario + manuales antes de un deploy/restore), deja solo el mas reciente
 * de ese dia.
 */
class BackupPruner
{
    public const DIR = 'backups';

    public static function prune(int $retentionDays): int
    {
        $cutoff = now()->subDays($retentionDays);

        $files = collect(Storage::disk('local')->files(self::DIR))
            ->map(fn ($path) => (object) [
                'path' => $path,
                'modified' => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($path), config('app.timezone')),
            ]);

        $deleted = 0;

        foreach ($files as $file) {
            if ($file->modified->lt($cutoff)) {
                Storage::disk('local')->delete($file->path);
                $deleted++;
            }
        }

        $remaining = $files->reject(fn ($file) => $file->modified->lt($cutoff));

        foreach ($remaining->groupBy(fn ($file) => $file->modified->toDateString()) as $sameDay) {
            if ($sameDay->count() <= 1) {
                continue;
            }

            $sameDay->sortByDesc('modified')->skip(1)->each(function ($file) use (&$deleted) {
                Storage::disk('local')->delete($file->path);
                $deleted++;
            });
        }

        return $deleted;
    }
}
