<?php

namespace Tests\Feature\Support;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use App\Support\KillAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido de un jugador (2026-08-28): "una columna con el número de días
 * conectado (1 por día, no vale reconnect) para promediar con las kills y
 * dar un ranking más justo". Se deriva de kills.occurred_at (días con al
 * menos un kill o una muerte) en vez de un tracker de conexiones nuevo -- no
 * hay ninguna tabla que guarde cada Connected; como evento propio, así que
 * un tracker nuevo solo tendría datos desde que se agregara; esto funciona
 * retroactivo con todo el historial ya cargado.
 */
class KillAggregatorDaysPlayedTest extends TestCase
{
    use RefreshDatabase;

    private function makeKill(Player $attacker, ?Player $victim, string $occurredAt, bool $isSuicide = false): Kill
    {
        $server = Server::firstOrCreate(['slug' => 'test-server'], [
            'name' => 'S', 'log_path' => '/tmp/x.log', 'rcon_host' => '127.0.0.1', 'rcon_port' => 28960,
            'rcon_password' => 'x', 'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $match = GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => $occurredAt]);
        $round = Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => $occurredAt]);

        return Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name,
            'victim_player_id' => $victim?->id, 'victim_guid' => $victim?->guid ?? 0, 'victim_name' => $victim?->last_name ?? 'Bot',
            'weapon' => 'kar98k_mp', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => $isSuicide,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_multiple_kills_the_same_day_count_as_one_day(): void
    {
        $attacker = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 2, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->makeKill($attacker, $victim, '2026-08-20 20:00:00');
        $this->makeKill($attacker, $victim, '2026-08-20 20:05:00');
        $this->makeKill($attacker, $victim, '2026-08-20 20:10:00');

        $rows = KillAggregator::aggregate(fn () => Kill::query());
        $row = $rows->firstWhere('player.id', $attacker->id);

        $this->assertSame(1, $row->days_played);
        $this->assertSame(3, $row->kills);
        $this->assertSame(3.0, $row->kills_per_day);
    }

    public function test_kills_on_different_days_count_separately(): void
    {
        $attacker = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 2, 'last_name' => 'V', 'last_name_plain' => 'V']);

        $this->makeKill($attacker, $victim, '2026-08-20 20:00:00');
        $this->makeKill($attacker, $victim, '2026-08-21 20:00:00');
        $this->makeKill($attacker, $victim, '2026-08-22 20:00:00');
        $this->makeKill($attacker, $victim, '2026-08-22 21:00:00');

        $rows = KillAggregator::aggregate(fn () => Kill::query());
        $row = $rows->firstWhere('player.id', $attacker->id);

        $this->assertSame(3, $row->days_played);
        $this->assertSame(4, $row->kills);
        $this->assertSame(1.3, $row->kills_per_day); // round(4/3, 1)
    }

    public function test_a_day_with_only_a_death_still_counts_as_a_day_played(): void
    {
        $attacker = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 2, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Victim only dies, never kills -- the day should still be counted for them
        // via the victim-side query, not just the attacker-side one.
        $this->makeKill($attacker, $victim, '2026-08-20 20:00:00');

        $rows = KillAggregator::aggregate(fn () => Kill::query());
        $victimRow = $rows->firstWhere('player.id', $victim->id);

        $this->assertSame(1, $victimRow->days_played);
        $this->assertSame(0, $victimRow->kills);
        $this->assertSame(0.0, $victimRow->kills_per_day, 'No kills that day, so the average is 0, not division by zero.');
    }

    public function test_killing_and_dying_on_the_same_day_only_counts_once(): void
    {
        $a = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $b = Player::create(['guid' => 2, 'last_name' => 'B', 'last_name_plain' => 'B']);

        $this->makeKill($a, $b, '2026-08-20 20:00:00'); // a kills b
        $this->makeKill($b, $a, '2026-08-20 20:05:00'); // b kills a, same day

        $rows = KillAggregator::aggregate(fn () => Kill::query());
        $rowA = $rows->firstWhere('player.id', $a->id);

        $this->assertSame(1, $rowA->days_played, 'Killing AND dying the same day must not count as 2 days.');
    }
}
