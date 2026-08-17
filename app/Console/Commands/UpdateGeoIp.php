<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateGeoIp extends Command
{
    /**
     * DB-IP publishes a free "Country Lite" database monthly (CC BY 4.0, no
     * account/license key needed) in the same MMDB format GeoLite2 uses, so the
     * existing geoip2/geoip2 reader in App\Services\GeoIp works unchanged. Swapped
     * to this after three separate MaxMind license keys all failed to authenticate
     * (account-side issue MaxMind support never resolved) — see CLAUDE.md.
     */
    protected $signature = 'geoip:update';

    protected $description = 'Descarga la última base de datos de países DB-IP (mensual, gratuita, sin cuenta)';

    public function handle(): int
    {
        $month = now()->format('Y-m');
        $url = "https://download.db-ip.com/free/dbip-country-lite-{$month}.mmdb.gz";

        $dir = storage_path('app/geoip');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpGz = $dir.'/dbip-country-lite.mmdb.gz.tmp';
        $tmpMmdb = $dir.'/country.mmdb.tmp';
        $target = $dir.'/country.mmdb';

        $this->info("Descargando {$url}...");
        $contents = @file_get_contents($url);
        if ($contents === false || strlen($contents) < 1_000_000) {
            $this->error('Descarga falló o el archivo es sospechosamente pequeño — no se reemplaza la base actual.');

            return self::FAILURE;
        }

        file_put_contents($tmpGz, $contents);

        $gz = gzopen($tmpGz, 'rb');
        $out = fopen($tmpMmdb, 'wb');
        while (! gzeof($gz)) {
            fwrite($out, gzread($gz, 1024 * 1024));
        }
        gzclose($gz);
        fclose($out);
        unlink($tmpGz);

        if (filesize($tmpMmdb) < 1_000_000) {
            $this->error('El archivo descomprimido quedó demasiado pequeño — se descarta.');
            unlink($tmpMmdb);

            return self::FAILURE;
        }

        rename($tmpMmdb, $target);
        @chown($target, 'www-data');
        @chgrp($target, 'www-data');
        @chmod($target, 0644);

        $this->info("Listo: {$target} (".round(filesize($target) / 1024 / 1024, 1).' MB)');

        return self::SUCCESS;
    }
}
