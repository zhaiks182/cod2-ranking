<?php

namespace App\Console\Commands;

use App\Support\BackupPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RunDatabaseBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Vuelca la base de datos con mysqldump, la comprime, y borra respaldos con mas de 10 dias (o duplicados del mismo dia)';

    private const RETENTION_DAYS = 10;

    private const DIR = 'backups';

    public function handle(): int
    {
        $config = config('database.connections.'.config('database.default'));

        $filename = 'cod2_stats_'.now()->format('Y-m-d_His').'.sql.gz';
        $relativePath = self::DIR.'/'.$filename;

        Storage::disk('local')->makeDirectory(self::DIR);
        $fullPath = Storage::disk('local')->path($relativePath);

        // La password va en un --defaults-extra-file temporal, no como argumento
        // -p directo -- un argumento de linea de comandos queda visible entero
        // para cualquier otro usuario del VPS via `ps aux` mientras el proceso
        // corre (confirmado en esta misma sesion: asi se encontro la password de
        // RCON de este mismo server). El archivo se crea con permisos 600 y se
        // borra apenas termina mysqldump, este o no este el dump.
        $credsFile = tempnam(sys_get_temp_dir(), 'db_backup_');
        file_put_contents($credsFile, "[client]\nuser={$config['username']}\npassword={$config['password']}\nhost={$config['host']}\nport={$config['port']}\n");
        chmod($credsFile, 0600);

        try {
            $dump = new Process([
                'mysqldump',
                '--defaults-extra-file='.$credsFile,
                '--single-transaction',
                '--quick',
                $config['database'],
            ]);
            $dump->setTimeout(300);
            $dump->mustRun();
        } finally {
            @unlink($credsFile);
        }

        file_put_contents('compress.zlib://'.$fullPath, $dump->getOutput());

        $this->info("Respaldo creado: {$relativePath} (".$this->humanSize(filesize($fullPath)).')');

        $this->prune();

        return self::SUCCESS;
    }

    private function prune(): void
    {
        $deleted = BackupPruner::prune(self::RETENTION_DAYS);

        if ($deleted > 0) {
            $this->info("Borrados {$deleted} respaldo(s) (mas de ".self::RETENTION_DAYS.' dias, o duplicados del mismo dia).');
        }
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 1).' KB';
    }
}
