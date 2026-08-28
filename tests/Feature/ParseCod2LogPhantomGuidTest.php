<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug real en producción (2026-08-28): ~27 de 69 filas de `players` (39%) eran
 * fantasmas de 0 kills/0 deaths -- mismo nombre y un guid a 1-2 caracteres de
 * distancia del guid real de un jugador activo, vistos minutos aparte. guid es
 * FNV-1a del HWID2 (ver HwidHasher) -- un HWID real distinto produce un número
 * scrambleado por el efecto avalancha, nunca "el mismo número menos un dígito".
 * Confirmado contra el código fuente de CoD2x (github.com/callofduty2x/CoD2x):
 * el comando "status" delega en el binario original del engine (ASM_CALL), así
 * que el guid ya calculado se corrompe en algún punto de la impresión/transporte,
 * no en un segundo hardware real.
 */
class ParseCod2LogPhantomGuidTest extends TestCase
{
    use RefreshDatabase;

    private function runLog(string $contents): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $contents);

        $server = Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => $logPath,
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        @unlink($logPath);
    }

    public function test_a_near_identical_guid_for_the_same_name_seconds_later_does_not_create_a_new_player(): void
    {
        $this->runLog(
            "  0:01 Connected;1370006686;2;Ekkaia\n".
            "  0:02 Connected;137000666;2;Ekkaia\n"
        );

        $this->assertSame(1, Player::count());
        $this->assertDatabaseHas('players', ['guid' => 1370006686, 'last_name_plain' => 'Ekkaia']);
        $this->assertDatabaseMissing('players', ['guid' => 137000666]);
    }

    public function test_a_genuinely_different_guid_for_the_same_name_still_creates_a_new_player(): void
    {
        // Regression guard: this is the real, legitimate MOKOS scenario (2026-08-28)
        // -- same person's HWID actually changed across sessions, confirmed by real
        // kills recorded under each guid. The numbers below aren't a near-edit of
        // one another, so the phantom-guid guard must not swallow this case.
        $this->runLog(
            "  0:01 Connected;1055257276;2;MOKOS\n".
            "  0:02 Connected;1640316810;2;MOKOS\n"
        );

        $this->assertSame(2, Player::count());
        $this->assertDatabaseHas('players', ['guid' => 1055257276]);
        $this->assertDatabaseHas('players', ['guid' => 1640316810]);
    }

    public function test_a_near_identical_guid_for_a_different_name_still_creates_a_new_player(): void
    {
        $this->runLog(
            "  0:01 Connected;1370006686;2;Ekkaia\n".
            "  0:02 Connected;137000666;2;SomeoneElse\n"
        );

        $this->assertSame(2, Player::count());
        $this->assertDatabaseHas('players', ['guid' => 1370006686]);
        $this->assertDatabaseHas('players', ['guid' => 137000666]);
    }
}
