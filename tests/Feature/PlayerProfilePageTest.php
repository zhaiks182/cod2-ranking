<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina de perfil separada (2026-09-01, a pedido del dueño -- bio/redes/
 * specs vivian mezclados con la pagina de stats de kills/muertes, "no me
 * gusta"). /jugadores/{guid}/perfil, solo alcanzable si el jugador esta
 * reclamado (sin site_user no hay nada que mostrar).
 */
class PlayerProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_bio_socials_and_specs(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create([
            'discord_id' => '221538919068467200', 'discord_username' => 'zhaiks', 'player_id' => $player->id,
            'bio' => 'Jugador desde 2003.', 'steam_url' => 'https://steamcommunity.com/id/zhaiks',
            'pc_cpu' => 'Ryzen 5600X', 'clan_tag' => 'Destino', 'role' => 'Fundador', 'preferred_role' => 'weapon_mp44',
            'country' => 'ec', 'language' => 'es',
        ]);

        $this->get(route('players.profile', $player))
            ->assertOk()
            ->assertSee('Jugador desde 2003.')
            ->assertSee('https://steamcommunity.com/id/zhaiks', false)
            ->assertSee('Ryzen 5600X')
            ->assertSee(__('Clan').': Destino')
            ->assertSee('Fundador')
            // "Rol preferido" ahora es un codigo de arma real, resuelto a
            // nombre bonito con WeaponCatalog::label() al mostrarse.
            ->assertSee(\App\Support\WeaponCatalog::label('weapon_mp44'))
            ->assertSee('Ecuador')
            ->assertSee('Español')
            // Banderas reales (2026-09-01) -- una para el pais (Ecuador), otra
            // para el idioma (es -> bandera de España, mismo criterio que ya
            // usa el selector de idioma del nav).
            ->assertSee('flagcdn.com/ec.svg', false)
            ->assertSee('flagcdn.com/es.svg', false)
            ->assertSee('https://discord.com/users/221538919068467200', false);
    }

    public function test_returns_404_when_the_player_has_no_claimed_account(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $this->get(route('players.profile', $player))->assertNotFound();
    }

    public function test_shows_a_link_back_to_the_stats_page(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => '1', 'discord_username' => 'zhaiks', 'player_id' => $player->id]);

        $this->get(route('players.profile', $player))
            ->assertOk()
            ->assertSee(route('players.show', $player), false);
    }
}
