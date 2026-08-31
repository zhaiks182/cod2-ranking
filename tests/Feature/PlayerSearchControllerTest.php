<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buscador público de jugadores (nav del sitio, 2026-08-31) -- busca por
 * nombre actual O cualquier alias historico, mismo criterio que ya usa
 * PlayerMergeController en el admin (ver CLAUDE.md, "Fusionar jugadores").
 */
class PlayerSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePlayer(array $overrides = []): Player
    {
        return Player::create(array_merge([
            'guid' => random_int(-2000000000, 2000000000),
            'last_name' => 'Test',
            'last_name_plain' => 'Test',
            'kills_total' => 0,
            'deaths_total' => 0,
        ], $overrides));
    }

    public function test_finds_a_player_by_current_name(): void
    {
        $player = $this->makePlayer(['guid' => 111, 'last_name_plain' => 'hardoso', 'kills_total' => 50]);

        $response = $this->getJson(route('players.search', ['q' => 'hard']));

        $response->assertOk();
        $response->assertJsonFragment(['guid' => 111, 'name' => 'hardoso', 'kills' => 50]);
    }

    public function test_finds_a_player_by_a_historical_alias_even_if_the_current_name_differs(): void
    {
        $player = $this->makePlayer(['guid' => 222, 'last_name_plain' => 'destination.zhaiks']);
        PlayerAlias::create(['player_id' => $player->id, 'name' => 'MOCOS', 'name_plain' => 'MOCOS', 'last_seen_at' => now()]);

        $response = $this->getJson(route('players.search', ['q' => 'MOCOS']));

        $response->assertOk();
        $response->assertJsonFragment(['guid' => 222, 'name' => 'destination.zhaiks']);
    }

    public function test_requires_at_least_two_characters(): void
    {
        $this->makePlayer(['last_name_plain' => 'ab']);

        $response = $this->getJson(route('players.search', ['q' => 'a']));

        $response->assertOk();
        $response->assertJson([]);
    }

    public function test_limits_to_8_results_ordered_by_kills(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->makePlayer(['last_name_plain' => "Player{$i}", 'kills_total' => $i]);
        }

        $response = $this->getJson(route('players.search', ['q' => 'Player']));

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(8, $data);
        $this->assertSame(10, $data[0]['kills']);
    }

    public function test_no_match_returns_empty_array(): void
    {
        $this->makePlayer(['last_name_plain' => 'hardoso']);

        $response = $this->getJson(route('players.search', ['q' => 'zzzznope']));

        $response->assertOk();
        $response->assertJson([]);
    }
}
