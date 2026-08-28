<?php

namespace Tests\Feature\Admin;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modulo independiente (2026-08-28, a pedido del dueño) -- antes era un botón
 * dentro de /adm_cod2/jugadores/fusionar, se separó a su propia pantalla con
 * el listado completo de jugadores (no depende de buscar primero).
 *
 * Borrar un jugador no debe borrar el historial de partidas -- kills.*_player_id
 * usa nullOnDelete() (misma familia que demos.match_id, ver
 * MatchDestroyDeletesDemosTest), así que el kill sobrevive con el guid/nombre
 * tal cual estaba, igual que ya pasa con los kills de un bot (guid=0, sin
 * player_id). Lo que sí desaparece es lo que solo existe para sostener ESE
 * player_id: alias y los acumuladores cacheados (cascadeOnDelete).
 */
class PlayerDeleteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_both_index_and_destroy(): void
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'X', 'last_name_plain' => 'X', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $this->get(route('admin.players.delete.index'))->assertRedirect(route('admin.login'));
        $this->delete(route('admin.players.delete.destroy', $player))->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_index_lists_every_player_not_just_ones_with_ip(): void
    {
        $admin = User::factory()->create();
        $player = Player::create(['guid' => 1, 'last_name' => 'SinIP', 'last_name_plain' => 'SinIP', 'ip' => null, 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $response = $this->actingAs($admin)->get(route('admin.players.delete.index'));

        $response->assertOk();
        $response->assertSee('SinIP');
    }

    public function test_destroying_a_player_keeps_their_kills_in_history_with_the_raw_guid_and_name(): void
    {
        $admin = User::factory()->create();
        $player = Player::create(['guid' => 42, 'last_name' => 'Genuine', 'last_name_plain' => 'Genuine', 'kills_total' => 1, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $server = Server::create(['name' => 'S', 'slug' => 's', 'log_path' => '/tmp/x.log', 'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'x', 'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true]);
        $match = GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);
        $round = Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now()]);

        $kill = Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $player->id, 'attacker_guid' => 42, 'attacker_name' => 'Genuine',
            'victim_player_id' => null, 'victim_guid' => 0, 'victim_name' => 'Bot',
            'weapon' => 'M1Garand', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false,
            'occurred_at' => now(),
        ]);
        $player->aliases()->create(['name' => 'Genuine', 'name_plain' => 'Genuine', 'last_seen_at' => now()]);

        $this->actingAs($admin)->delete(route('admin.players.delete.destroy', $player))->assertRedirect();

        $this->assertDatabaseMissing('players', ['id' => $player->id]);
        $this->assertDatabaseMissing('player_aliases', ['player_id' => $player->id]);

        $kill->refresh();
        $this->assertNull($kill->attacker_player_id);
        $this->assertSame(42, $kill->attacker_guid);
        $this->assertSame('Genuine', $kill->attacker_name);

        $this->assertDatabaseHas('admin_actions', ['action' => 'players.destroy']);
    }

    public function test_guests_are_redirected_to_login_for_bulk_destroy(): void
    {
        $this->delete(route('admin.players.delete.bulk-zero-activity'))->assertRedirect(route('admin.login'));
    }

    /**
     * Caso real (2026-08-28): 27+ filas fantasma con 0 kills/0 deaths por el
     * bug de guid corrupto en tránsito (ya arreglado en upsertPlayer(), ver
     * CLAUDE.md) -- ninguna tiene stats reales que perder.
     */
    public function test_bulk_destroy_removes_only_players_with_zero_kills_and_zero_deaths(): void
    {
        $admin = User::factory()->create();

        $phantom1 = Player::create(['guid' => 1, 'last_name' => 'Phantom1', 'last_name_plain' => 'Phantom1', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $phantom2 = Player::create(['guid' => 2, 'last_name' => 'Phantom2', 'last_name_plain' => 'Phantom2', 'kills_total' => 0, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $onlyKills = Player::create(['guid' => 3, 'last_name' => 'OnlyKills', 'last_name_plain' => 'OnlyKills', 'kills_total' => 5, 'deaths_total' => 0, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $onlyDeaths = Player::create(['guid' => 4, 'last_name' => 'OnlyDeaths', 'last_name_plain' => 'OnlyDeaths', 'kills_total' => 0, 'deaths_total' => 3, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);
        $realPlayer = Player::create(['guid' => 5, 'last_name' => 'Real', 'last_name_plain' => 'Real', 'kills_total' => 100, 'deaths_total' => 50, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $response = $this->actingAs($admin)->delete(route('admin.players.delete.bulk-zero-activity'));

        $response->assertRedirect();

        $this->assertDatabaseMissing('players', ['id' => $phantom1->id]);
        $this->assertDatabaseMissing('players', ['id' => $phantom2->id]);
        $this->assertDatabaseHas('players', ['id' => $onlyKills->id]);
        $this->assertDatabaseHas('players', ['id' => $onlyDeaths->id]);
        $this->assertDatabaseHas('players', ['id' => $realPlayer->id]);
        $this->assertDatabaseHas('admin_actions', ['action' => 'players.destroy-bulk-zero-activity']);
    }

    public function test_bulk_destroy_with_nothing_to_delete_does_not_error(): void
    {
        $admin = User::factory()->create();
        Player::create(['guid' => 1, 'last_name' => 'Real', 'last_name_plain' => 'Real', 'kills_total' => 10, 'deaths_total' => 5, 'headshots_total' => 0, 'grenade_kills_total' => 0, 'suicides_total' => 0]);

        $response = $this->actingAs($admin)->delete(route('admin.players.delete.bulk-zero-activity'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('admin_actions', ['action' => 'players.destroy-bulk-zero-activity']);
    }
}
