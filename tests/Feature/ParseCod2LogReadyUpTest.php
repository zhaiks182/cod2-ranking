<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduce en vivo (2026-08-24): el motor manda RoundStart; tambien para la
 * fase de ready-up ("Round 0" en el _match_info del InitGame:), no solo para
 * rondas reales -- confirmado en games_mp.log real:
 *
 *   0:24 InitGame: ..._match_info\Round 0 | MR12 Ready-up\..._match_team1\-\_match_team2\-...
 *   0:24 RoundStart;
 *
 * Con ambos equipos vacios (nadie en el server todavia). El parser abria
 * partida igual porque solo excluia el gametype "strat" (ver el comentario
 * de esa rama en ParseCod2Log::processLine()), sin mirar nunca la etiqueta
 * "Round N" del propio _match_info -- asi que cualquier RoundStart; durante
 * el ready-up, de cualquier gametype, quedaba mal clasificado como partida
 * real. Confirmado con el dueño: "Una partida inicia cuando todos aplican
 * ready up" -- Round 0 nunca deberia contar.
 */
class ParseCod2LogReadyUpTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(string $logPath): Server
    {
        return Server::create([
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
    }

    public function test_round_0_readyup_with_empty_teams_does_not_create_a_match(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 0 | MR12 Ready-up\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '',
        ]));

        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(0, GameMatch::count(), 'Round 0 (ready-up) should never create a match.');
        $this->assertSame(0, Round::count(), 'Round 0 (ready-up) should never create a round.');

        @unlink($logPath);
    }

    public function test_round_1_after_readyup_still_creates_a_real_match(): void
    {
        // Regression guard: the fix must only skip Round 0, not every RoundStart;.
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 0 | MR12 Ready-up\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:36 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:36 RoundStart;',
            '',
        ]));

        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(1, GameMatch::count(), 'A real Round 1 RoundStart; must still open a match.');
        $this->assertSame(1, Round::count());
        $this->assertSame('mp_toujane_fix', GameMatch::first()->map);
        $this->assertSame('sd', GameMatch::first()->gametype);

        @unlink($logPath);
    }
}
