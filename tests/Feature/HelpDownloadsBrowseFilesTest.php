<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * /descargas/archivos navega la carpeta real de fast-download del gameserver
 * (cod2.fast_download_root) con subcarpetas incluidas -- reemplaza el listado plano
 * de un solo nivel que vivía inline en /descargas. Pedido del dueño (2026-08-29)
 * tras ver el fast-download page de otro clan (verindra.ddns.net) y querer un
 * explorador real, no una tabla fija.
 */
class HelpDownloadsBrowseFilesTest extends TestCase
{
    private function makeRoot(): string
    {
        $dir = sys_get_temp_dir().'/cod2_fastdl_root_'.uniqid();
        File::makeDirectory($dir.'/main', recursive: true);
        File::put($dir.'/main/zpam408.iwd', str_repeat('a', 1024 * 1024 * 2));
        File::put($dir.'/main/zpam_maps_v7.iwd', str_repeat('a', 1024 * 1024 * 5));

        config([
            'cod2.fast_download_root' => $dir,
            'cod2.fast_download_public_url' => 'http://151.245.32.43/cod2',
        ]);

        return $dir;
    }

    public function test_the_downloads_page_links_to_the_browse_route(): void
    {
        $response = $this->get(route('downloads'));

        $response->assertOk();
        $response->assertSee('Mod Pack');
        $response->assertSee(route('downloads.browse'));
    }

    public function test_root_lists_subdirectories(): void
    {
        $dir = $this->makeRoot();

        $response = $this->get(route('downloads.browse'));

        $response->assertOk();
        $response->assertSee('main/');

        File::deleteDirectory($dir);
    }

    /**
     * Bug real (2026-08-30): la vista era un documento HTML standalone (su propio
     * <html>/<head>/<body>, sin @extends('layouts.app')) -- perdia el header/nav del
     * sitio, distinto a cualquier otra pagina publica. El dueño pidio que fuera igual
     * al resto. De paso, el <title> decia "Fast DL · Zhaiks" en vez de "Fast Download".
     */
    public function test_uses_the_site_layout_with_the_standard_nav_and_title(): void
    {
        $dir = $this->makeRoot();

        $response = $this->get(route('downloads.browse'));

        $response->assertOk();
        $response->assertSee('<title>Fast Download</title>', false);
        $response->assertSee(route('dashboard'), false);
        $response->assertSee('LEADERBOARDS');

        File::deleteDirectory($dir);
    }

    public function test_navigating_into_a_subdirectory_lists_its_files_with_download_links(): void
    {
        $dir = $this->makeRoot();

        $response = $this->get(route('downloads.browse', ['path' => 'main']));

        $response->assertOk();
        $response->assertSee('zpam408.iwd');
        $response->assertSee('zpam_maps_v7.iwd');
        $response->assertSee('2.0 MB');
        $response->assertSee('5.0 MB');
        $response->assertSee('http://151.245.32.43/cod2/main/zpam408.iwd', false);

        File::deleteDirectory($dir);
    }

    public function test_path_traversal_outside_the_root_is_rejected(): void
    {
        $dir = $this->makeRoot();

        $response = $this->get(route('downloads.browse', ['path' => '../../etc']));

        $response->assertNotFound();

        File::deleteDirectory($dir);
    }

    public function test_a_missing_root_returns_not_found(): void
    {
        config(['cod2.fast_download_root' => sys_get_temp_dir().'/cod2_fastdl_does_not_exist']);

        $response = $this->get(route('downloads.browse'));

        $response->assertNotFound();
    }
}
