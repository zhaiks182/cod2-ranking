<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * /descargas lista la carpeta real de fast-download del gameserver (la misma
 * que usa sv_wwwBaseURL) en vez de links hardcodeados -- asi se mantiene sola
 * cuando se sube una version nueva del mod/mapas. Pedido del dueño (2026-08-29)
 * despues de ver un fast-download page de otro clan (verindra.ddns.net) y
 * querer algo similar en el menu de Ayuda.
 */
class HelpDownloadsListsFastDownloadFilesTest extends TestCase
{
    public function test_lists_files_found_in_the_configured_directory(): void
    {
        $dir = sys_get_temp_dir().'/cod2_fastdl_test_'.uniqid();
        File::makeDirectory($dir);
        File::put($dir.'/zpam408.iwd', str_repeat('a', 1024 * 1024 * 2));
        File::put($dir.'/zpam_maps_v7.iwd', str_repeat('a', 1024 * 1024 * 5));

        config(['cod2.fast_download_path' => $dir]);

        $response = $this->get(route('downloads'));

        $response->assertOk();
        $response->assertSee('zpam408.iwd');
        $response->assertSee('zpam_maps_v7.iwd');
        $response->assertSee('2.0 MB');
        $response->assertSee('5.0 MB');

        File::deleteDirectory($dir);
    }

    public function test_shows_no_extra_section_when_directory_is_missing(): void
    {
        config(['cod2.fast_download_path' => sys_get_temp_dir().'/cod2_fastdl_does_not_exist']);

        $response = $this->get(route('downloads'));

        $response->assertOk();
        $response->assertDontSee('Mod y mapas del server');
    }
}
