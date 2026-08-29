<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Support\Carbon;

class HelpController extends Controller
{
    public function faq()
    {
        $server = Server::where('is_active', true)->orderBy('name')->first();

        $connectString = trim(($server?->connect_ip ?? '').':'.($server?->connect_port ?? ''), ':');
        if ($server?->join_password) {
            $connectString .= '; password '.$server->join_password;
        }

        // Protocolo custom que registra el cliente de CoD2x -- el navegador lo abre
        // como cualquier link externo (steam://, mailto:, etc.), sin necesidad de
        // abrir la consola a mano. Solo ip:puerto, sin password (el juego pide la
        // password aparte si el server la tiene).
        $connectUri = $server ? "cod2://{$server->connect_ip}:{$server->connect_port}" : null;

        return view('help.faq', compact('connectString', 'connectUri'));
    }

    public function downloads()
    {
        return view('help.downloads');
    }

    /**
     * Navega la carpeta real de fast-download del gameserver (cod2.fast_download_root)
     * -- un explorador estilo "Index of /" que refleja exactamente lo que Apache sirve
     * en cod2.fast_download_public_url, con subcarpetas incluidas. Reemplaza el listado
     * plano anterior (un solo nivel, sin navegacion) a pedido del dueño (2026-08-29) tras
     * ver el fast-download page de otro clan (verindra.ddns.net) y querer algo similar.
     */
    public function browseFiles(string $path = '')
    {
        $root = realpath(config('cod2.fast_download_root'));
        abort_unless($root, 404);

        $relative = trim($path, '/');
        $target = realpath($relative === '' ? $root : $root.'/'.$relative);

        // Guardia contra path traversal: el resuelto tiene que seguir adentro de $root.
        abort_unless($target && ($target === $root || str_starts_with($target, $root.'/')), 404);
        abort_unless(is_dir($target), 404);

        $entries = collect(scandir($target) ?: [])
            ->reject(fn ($name) => in_array($name, ['.', '..'], true))
            ->map(function ($name) use ($target, $relative) {
                $full = $target.'/'.$name;
                $isDir = is_dir($full);

                return [
                    'name' => $name,
                    'is_dir' => $isDir,
                    'size_human' => $isDir ? null : $this->formatBytes(filesize($full)),
                    'modified_human' => Carbon::createFromTimestamp(filemtime($full))->format('d/m/Y H:i'),
                    'path' => ltrim($relative.'/'.$name, '/'),
                ];
            })
            ->sortBy(fn ($entry) => ($entry['is_dir'] ? '0' : '1').strtolower($entry['name']))
            ->values();

        $breadcrumbs = collect();
        if ($relative !== '') {
            $accumulated = '';
            foreach (explode('/', $relative) as $segment) {
                $accumulated = ltrim($accumulated.'/'.$segment, '/');
                $breadcrumbs->push(['name' => $segment, 'path' => $accumulated]);
            }
        }

        $parentPath = null;
        if ($relative !== '') {
            $parentPath = dirname($relative);
            $parentPath = $parentPath === '.' ? '' : $parentPath;
        }

        return view('help.downloads-browse', [
            'entries' => $entries,
            'breadcrumbs' => $breadcrumbs,
            'parentPath' => $parentPath,
            'publicBaseUrl' => rtrim(config('cod2.fast_download_public_url'), '/'),
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = $bytes;
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, 1).' '.$units[$i];
    }
}
