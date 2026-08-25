<?php

namespace Tests\Feature;

use App\Models\PlayerWeaponPick;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerWeaponPickSeasonTest extends TestCase
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

    private function pickupLog(): string
    {
        return implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_toujane_fix\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Weapon;12345;0;DESTINATION;weapon_mp44',
            '',
        ]);
    }

    public function test_a_new_weapon_pick_gets_the_currently_active_season(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $this->pickupLog());
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $pick = PlayerWeaponPick::first();
        $this->assertNotNull($pick);
        $this->assertSame(Season::current()->id, $pick->season_id);

        @unlink($logPath);
    }

    public function test_a_pick_after_closing_a_season_gets_the_new_one_as_a_separate_row(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'games_mp_');
        file_put_contents($logPath, $this->pickupLog());
        $server = $this->makeServer($logPath);

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();
        $oldPick = PlayerWeaponPick::first();
        $oldSeasonId = $oldPick->season_id;

        Season::current()->update(['ended_at' => now()]);
        $newSeason = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        // Mismo jugador, misma arma, log nuevo -- debe crear una fila NUEVA (season_id
        // distinto), no incrementar la de la temporada vieja.
        file_put_contents($logPath, implode("\n", [
            '  0:00 InitGame: \_match_info\-\_match_score\-\_match_team1\-\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_railyard\protocol\120\shortversion\1.4.6.8',
            '  0:24 InitGame: \_match_info\Round 1 | MR12 \_match_score\-\_match_team1\DESTINATION\_match_team2\-\_zpam\4.08\g_antilag\1\g_gametype\sd\gamename\Call of Duty 2\mapname\mp_railyard\protocol\120\shortversion\1.4.6.8',
            '  0:24 RoundStart;',
            '  0:30 Weapon;12345;0;DESTINATION;weapon_mp44',
            '',
        ]));

        $this->artisan('cod2:parse-log', ['--server' => $server->id])->assertSuccessful();

        $this->assertSame(2, PlayerWeaponPick::count());
        $newPick = PlayerWeaponPick::where('season_id', $newSeason->id)->first();
        $this->assertNotNull($newPick);
        $this->assertSame(1, $newPick->picks);
        $this->assertSame(1, PlayerWeaponPick::find($oldPick->id)->fresh()->picks);

        @unlink($logPath);
    }
}
