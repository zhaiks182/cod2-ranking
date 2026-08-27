<?php

namespace Tests\Feature;

use App\Support\BackupPruner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Extraido de RunDatabaseBackup::prune() (2026-08-27) para poder testear la
 * logica de poda sin depender de mysqldump -- el comando en si no corre en el
 * entorno de tests (SQLite, sin MySQL real).
 */
class BackupPrunerTest extends TestCase
{
    use RefreshDatabase;

    private function putBackupAt(string $name, Carbon $when): void
    {
        Storage::disk('local')->put(BackupPruner::DIR.'/'.$name, 'x');
        touch(Storage::disk('local')->path(BackupPruner::DIR.'/'.$name), $when->getTimestamp());
    }

    private function remainingFiles(): array
    {
        return collect(Storage::disk('local')->files(BackupPruner::DIR))
            ->map(fn ($path) => basename($path))
            ->sort()
            ->values()
            ->all();
    }

    public function test_keeps_only_the_most_recent_backup_of_each_day(): void
    {
        Storage::fake('local');

        $this->putBackupAt('cod2_stats_2026-08-27_030002.sql.gz', now()->setTime(3, 0));
        $this->putBackupAt('cod2_stats_2026-08-27_090714.sql.gz', now()->setTime(9, 7));
        $this->putBackupAt('cod2_stats_2026-08-27_145255.sql.gz', now()->setTime(14, 52));
        $this->putBackupAt('cod2_stats_2026-08-26_030002.sql.gz', now()->subDay()->setTime(3, 0));

        $deleted = BackupPruner::prune(10);

        $this->assertSame(2, $deleted); // las 2 mas viejas del 27, la de las 14:52 sobrevive
        $this->assertSame([
            'cod2_stats_2026-08-26_030002.sql.gz',
            'cod2_stats_2026-08-27_145255.sql.gz',
        ], $this->remainingFiles());
    }

    public function test_deletes_backups_older_than_retention_regardless_of_same_day_duplicates(): void
    {
        Storage::fake('local');

        $this->putBackupAt('cod2_stats_old.sql.gz', now()->subDays(15));
        $this->putBackupAt('cod2_stats_recent.sql.gz', now());

        $deleted = BackupPruner::prune(10);

        $this->assertSame(1, $deleted);
        $this->assertSame(['cod2_stats_recent.sql.gz'], $this->remainingFiles());
    }

    public function test_a_single_backup_per_day_is_never_touched(): void
    {
        Storage::fake('local');

        $this->putBackupAt('cod2_stats_2026-08-27_030002.sql.gz', now());
        $this->putBackupAt('cod2_stats_2026-08-26_030002.sql.gz', now()->subDay());

        $deleted = BackupPruner::prune(10);

        $this->assertSame(0, $deleted);
        $this->assertSame([
            'cod2_stats_2026-08-26_030002.sql.gz',
            'cod2_stats_2026-08-27_030002.sql.gz',
        ], $this->remainingFiles());
    }
}
