<?php

namespace Tests\Feature\Support;

use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * <x-player-icon> (2026-08-28) -- componente compartido usado en todo el sitio
 * donde aparece el nombre de un jugador, para no duplicar el mismo <img>
 * condicional en cada view por separado.
 */
class PlayerIconComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_nothing_for_a_player_without_an_icon(): void
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A']);

        $html = (string) view('components.player-icon', ['player' => $player])->render();

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_renders_nothing_for_a_null_player(): void
    {
        $html = (string) view('components.player-icon', ['player' => null])->render();

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_renders_the_icon_url_when_present(): void
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A', 'icon_path' => 'player-icons/1.png']);

        $html = (string) view('components.player-icon', ['player' => $player])->render();

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('player-icons/1.png', $html);
    }
}
