<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reportado por el dueño (2026-08-30): la partida de Toujane del 2026-08-29
 * (match_id=116) mostraba "Sin kills registradas en esta ronda" en la ronda
 * 2. Confirmado contra el games_mp.log real: el motor manda un RoundStart;
 * normal, todos se conectan, hacen ready-up (ReadyupDone;) -- y 10 segundos
 * despues, un TOO; (timeout, no documentado en CLAUDE.md hasta ahora) aborta
 * el intento sin que nadie llegue a jugar. El servidor sigue con
 * ShutdownGame: (nunca un RoundEnd; propio) y reinicia con un RoundStart;
 * nuevo segundos despues -- confirmado dos veces en la misma partida real
 * (rondas 2 y 23 de match_id=116), mismo patron exacto ambas veces.
 *
 * ParseCod2Log::openRound() crea una fila en `rounds` en cada RoundStart; sin
 * excepcion, asi que este intento abortado quedaba como una ronda fantasma
 * de 0 kills en el listado de la partida -- mismo espiritu que el filtro ya
 * existente de "Round 0"/"strat" (ver ParseCod2LogReadyUpTest), pero para un
 * caso que solo se puede detectar del lado del CIERRE de la ronda (no se
 * sabe de antemano, al abrir el RoundStart;, si va a terminar en TOO;).
 *
 * Senal usada para distinguir: una ronda que llega a ShutdownGame: sin haber
 * pasado antes por RoundEnd; (osea, `ended_at` todavia null en ese momento)
 * Y sin ningun Kill; registrado nunca se jugo de verdad -- se borra en vez de
 * dejarla como ronda vacia. Una ronda real siempre pasa por RoundEnd; primero
 * (que ya deja `ended_at` puesto), asi que el branch de ShutdownGame: no le
 * hace nada -- este fix no le cambia el comportamiento a ninguna ronda real.
 */
class ParseCod2LogTimeoutAbortTest extends TestCase
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

    public function test_a_round_aborted_by_too_before_any_kill_is_not_kept_as_a_phantom_empty_round(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:16 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\ha\_match_team2\an\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:16 RoundStart;',
            '  0:16 Connected;111;1;PlayerA',
            '  0:16 Connected;222;2;PlayerB',
            '  0:47 ReadyupDone;',
            '  0:57 TOO;',
            '  0:57 ShutdownGame:',
            '  0:57 InitGame: \_match_info\Round 2 | MR12 Timeout\_match_score\-\_match_team1\ha\_match_team2\an\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:57 RoundStart;',
            '  0:57 Connected;111;1;PlayerA',
            '  0:57 Connected;222;2;PlayerB',
            '  1:13 Kill;222;2;PlayerB;allies;111;1;PlayerA;axis;m1garand_mp;135;MOD_HEAD_SHOT;head',
            '  1:20 RoundEnd;',
            '  1:20 Winners;axis;111;PlayerA',
            '  1:20 Losers;allies;222;PlayerB',
            '  1:20 Score;allies;0;axis;1',
            '  1:26 ShutdownGame:',
            '',
        ]));

        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(1, GameMatch::count());
        $this->assertSame(1, Round::count(), 'El intento abortado por TOO; no debe dejar una ronda fantasma.');
        $this->assertSame(1, Kill::count());

        $round = Round::first();
        $this->assertNotNull($round->ended_at);
        $this->assertSame([111], $round->winner_guids);

        @unlink($logPath);
    }

    public function test_a_normal_round_closed_by_roundend_then_shutdowngame_is_unaffected(): void
    {
        // Guardia de regresion: el fix solo debe activarse cuando ShutdownGame:
        // encuentra una ronda SIN RoundEnd; previo. Una ronda real siempre pasa
        // por RoundEnd; primero (que ya deja ended_at puesto), asi que el
        // ShutdownGame: que le sigue no debe borrarla ni tocarla.
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:16 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\ha\_match_team2\an\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:16 RoundStart;',
            '  0:16 Connected;111;1;PlayerA',
            '  0:16 Connected;222;2;PlayerB',
            '  0:30 Kill;222;2;PlayerB;allies;111;1;PlayerA;axis;m1garand_mp;135;MOD_HEAD_SHOT;head',
            '  0:35 RoundEnd;',
            '  0:35 Winners;axis;111;PlayerA',
            '  0:35 Losers;allies;222;PlayerB',
            '  0:35 Score;allies;0;axis;1',
            '  0:40 ShutdownGame:',
            '',
        ]));

        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(1, Round::count());
        $this->assertSame(1, Kill::count());
        $this->assertNotNull(Round::first()->ended_at);
        $this->assertSame([111], Round::first()->winner_guids);

        @unlink($logPath);
    }
}
