<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerMatchExtra;
use App\Models\PlayerServerStat;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseCod2LogExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(string $logPath): Server
    {
        return Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => $logPath,
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    public function test_bomb_damage_and_disconnect_lines_populate_player_match_extras_and_keep_the_flat_counter(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Damage;222;1;Victim;axis;111;0;Attacker;allies;weapon_mp44;25;MOD_RIFLE_BULLET;torso_upper',
            '  0:35 Bomb;111;0;allies;Attacker;bomb_plant',
            '  0:40 Disconnected;111;0;Attacker',
            '',
        ]));
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $match = GameMatch::firstOrFail();
        $attacker = Player::where('guid', 111)->firstOrFail();
        $victim = Player::where('guid', 222)->firstOrFail();

        $attackerExtra = PlayerMatchExtra::where(['player_id' => $attacker->id, 'match_id' => $match->id])->firstOrFail();
        $this->assertSame(25, $attackerExtra->damage_dealt);
        $this->assertSame(0, $attackerExtra->damage_taken);
        $this->assertSame(1, $attackerExtra->bomb_plants);
        $this->assertSame(0, $attackerExtra->bomb_defuses);
        $this->assertSame(1, $attackerExtra->mid_round_disconnects);

        $victimExtra = PlayerMatchExtra::where(['player_id' => $victim->id, 'match_id' => $match->id])->firstOrFail();
        $this->assertSame(25, $victimExtra->damage_taken);
        $this->assertSame(0, $victimExtra->bomb_plants);

        // Regresion: el acumulador plano existente sigue actualizandose exactamente
        // igual que antes de este cambio -- ?season=all (Task 3) depende de esto.
        $attackerStat = PlayerServerStat::where(['player_id' => $attacker->id, 'server_id' => $server->id])->firstOrFail();
        $this->assertSame(25, $attackerStat->damage_dealt);
        $this->assertSame(1, $attackerStat->bomb_plants);
        $this->assertSame(1, $attackerStat->mid_round_disconnects);

        @unlink($logPath);
    }
}
