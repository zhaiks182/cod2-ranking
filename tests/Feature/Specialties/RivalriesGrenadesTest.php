<?php

namespace Tests\Feature\Specialties;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido de un jugador (2026-08-28): "una vista de tipo quien mato a quien con
 * granada". Reusa specialties.rivalries con ?type=grenades -- misma logica de
 * emparejamiento, filtrando is_grenade=true, con el piso bajado de 3 a 2 bajas
 * (las granadas son mucho mas raras que las bajas en general).
 */
class RivalriesGrenadesTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function realMatchWithKills(Player $attacker, Player $victim, int $count, bool $isGrenade): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'season_id' => \App\Models\Season::current()->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        for ($i = 1; $i <= 13; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);
        }

        $round = $match->rounds()->first();

        for ($i = 0; $i < $count; $i++) {
            Kill::create([
                'round_id' => $round->id, 'match_id' => $match->id,
                'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
                'victim_player_id' => $victim->id, 'victim_guid' => $victim->guid, 'victim_name' => $victim->last_name, 'victim_team' => 'axis',
                'weapon' => $isGrenade ? 'frag_grenade_mp' : 'weapon_mp44', 'damage' => 100,
                'mod' => $isGrenade ? 'MOD_GRENADE' : 'MOD_RIFLE_BULLET', 'is_headshot' => false, 'is_grenade' => $isGrenade,
                'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
            ]);
        }

        return $match;
    }

    public function test_grenades_tab_only_counts_grenade_kills_with_a_lower_floor(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // 2 kills de granada (bajo el piso de 3 de la pestaña general, pero cumple
        // el piso de 2 de granadas) + 5 kills normales (no deberian sumar acá).
        $this->realMatchWithKills($attacker, $victim, count: 2, isGrenade: true);
        $this->realMatchWithKills($attacker, $victim, count: 5, isGrenade: false);

        $response = $this->get(route('specialties.rivalries', ['server' => $this->server->slug, 'type' => 'grenades']));
        $response->assertOk();

        $row = collect($response->viewData('rivalries'))
            ->first(fn ($r) => $r->nemesis?->id === $attacker->id && $r->victim?->id === $victim->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row->count, 'Solo las 2 bajas de granada, no las 5 normales.');

        $tabs = collect($response->viewData('tabs'));
        $this->assertTrue($tabs->firstWhere('label', '💣 Con granadas')['active']);
    }

    public function test_general_tab_is_unaffected_by_grenade_kills_and_keeps_the_floor_of_three(): void
    {
        $attacker = Player::create(['guid' => 111, 'last_name' => 'A', 'last_name_plain' => 'A']);
        $victim = Player::create(['guid' => 222, 'last_name' => 'V', 'last_name_plain' => 'V']);

        // Solo 2 de granada -- bajo el piso de 3 de la pestaña general, no debe aparecer ahí.
        $this->realMatchWithKills($attacker, $victim, count: 2, isGrenade: true);

        $response = $this->get(route('specialties.rivalries', ['server' => $this->server->slug]));
        $response->assertOk();

        $row = collect($response->viewData('rivalries'))
            ->first(fn ($r) => $r->nemesis?->id === $attacker->id && $r->victim?->id === $victim->id);
        $this->assertNull($row, 'Con solo 2 bajas totales, no debe alcanzar el piso de 3 de la pestaña general.');
    }
}
