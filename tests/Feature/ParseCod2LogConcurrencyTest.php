<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Bug real en vivo (2026-08-24): Schedule::withoutOverlapping() solo protege
 * la invocación del propio scheduler -- no hace nada contra un
 * `php artisan cod2:parse-log` corrido a mano en paralelo al cron real.
 * Confirmado: correr el comando a mano varias veces durante una sesión de
 * debugging en vivo, mientras el cron seguía corriendo cada minuto, hizo que
 * el "perdedor" de la carrera arrancara con current_round_id/current_match_id
 * desactualizados (pisados por el otro proceso) -- cualquier kill que
 * "parseaba" con ese estado viejo caía en el descarte de entre-rondas de
 * recordKill() (esa ronda ya tenía ended_at puesto), perdiendo ~66% de los
 * kills reales de un jugador en esa partida, aunque las líneas del log nunca
 * se perdieron. Este lock hace que una segunda corrida concurrente (sin
 * importar el origen) se salte en vez de competir.
 */
class ParseCod2LogConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_concurrent_run_for_the_same_server_is_skipped(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, "  0:01 say;1;0;someone;hello\n");

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

        $lock = Cache::lock("cod2:parse-log:server:{$server->id}", 120);
        $this->assertTrue($lock->get(), 'Precondition: must be able to acquire the lock manually first.');

        $this->artisan('cod2:parse-log', ['--server' => $server->id])
            ->expectsOutputToContain('Another cod2:parse-log run is already in progress')
            ->assertSuccessful();

        $this->assertDatabaseMissing('log_parser_state', ['server_id' => $server->id]);

        $lock->release();

        @unlink($logPath);
    }

    public function test_run_proceeds_normally_once_the_lock_is_free(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, "  0:01 say;1;0;someone;hello\n");

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

        $this->assertDatabaseHas('log_parser_state', ['server_id' => $server->id]);

        @unlink($logPath);
    }
}
