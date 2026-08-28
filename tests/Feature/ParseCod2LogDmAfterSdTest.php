<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug real reportado por jugadores (2026-08-28): kills de DM/HQ/CTF contaban
 * para el ranking de SD. Confirmado en producción: round_id=1555 (tag "sd",
 * heredado de match_id=107) tenía 86 kills que en realidad ocurrieron 20 horas
 * después, durante una sesión de DM real -- hardoso y Shyne terminaron con
 * kills/deaths inflados.
 *
 * Causa raíz en recordKill(): DM nunca manda RoundStart; (a diferencia de SD),
 * así que un Kill; es la única señal de que arrancó una sesión nueva. El
 * código viejo solo abría una ronda nueva si $currentRound era null -- si
 * quedaba una ronda de SD ya terminada (`ended_at` puesto) de la partida
 * anterior, el chequeo `$pendingGametype !== 'dm'` dejaba pasar el fallthrough
 * silencioso y las kills de DM se seguían atribuyendo a esa ronda vieja de SD.
 */
class ParseCod2LogDmAfterSdTest extends TestCase
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

    public function test_dm_kills_after_an_sd_round_ends_open_a_new_match_instead_of_counting_as_sd(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12\_match_score\-\_match_team1\A\_match_team2\B\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Kill;100;0;Victim;allies;200;1;Attacker;axis;kar98k_mp;50;MOD_RIFLE_BULLET;none',
            '  1:00 RoundEnd;',
            // DM never sends RoundStart; -- a new InitGame: with g_gametype=dm followed
            // directly by a Kill; is the real-world shape of this bug.
            '  1:05 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\dm\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  1:10 Kill;100;0;Victim;allies;200;1;Attacker;axis;kar98k_mp;50;MOD_RIFLE_BULLET;none',
            '',
        ]));

        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(2, GameMatch::count(), 'DM kill must open its own match, not reuse the ended SD one.');
        $this->assertSame(2, Round::count());
        $this->assertSame(['sd', 'dm'], GameMatch::orderBy('id')->pluck('gametype')->all());

        $attacker = Player::where('guid', 200)->first();
        $this->assertSame(1, $attacker->kills_total, 'Only the real SD kill should count -- the DM kill must not.');

        $dmMatch = GameMatch::where('gametype', 'dm')->first();
        $this->assertSame(1, Kill::where('match_id', $dmMatch->id)->count());

        @unlink($logPath);
    }

    public function test_a_stray_kill_during_the_same_matchs_readyup_gap_is_still_discarded(): void
    {
        // Regression guard: the fix must not stop discarding SD's own aim-trainer
        // warmup stray kills between a round ending and the next RoundStart;.
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12\_match_score\-\_match_team1\A\_match_team2\B\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Kill;100;0;Victim;allies;200;1;Attacker;axis;kar98k_mp;50;MOD_RIFLE_BULLET;none',
            '  1:00 RoundEnd;',
            // Still "sd" pending -- same match's ready-up gap, not a new session.
            '  1:05 Kill;100;0;Victim;allies;200;1;Attacker;axis;kar98k_mp;50;MOD_RIFLE_BULLET;none',
            '',
        ]));

        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(1, GameMatch::count(), 'The stray same-gametype kill must not open a second match.');
        $this->assertSame(1, Kill::count(), 'The stray kill must be discarded entirely.');

        $attacker = Player::where('guid', 200)->first();
        $this->assertSame(1, $attacker->kills_total);

        @unlink($logPath);
    }
}
