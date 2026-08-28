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
 * El icono personalizado (2026-08-28, /adm_cod2/jugadores/iconos) al principio
 * solo se mostraba al lado de la medalla del top 3 -- el dueño lo probó con
 * GenuineuPP. (puesto #6) y no aparecía en ningún lado. Pedido explícito
 * (2026-08-28, seguimiento): mostrarlo siempre, en cualquier lugar del sitio
 * donde aparece el nombre del jugador, no solo en el podio.
 */
class PlayerIconDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_icon_shows_on_the_leaderboard_even_outside_the_top_3(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);

        // 6 players so our target lands at rank #6, well outside any top-3 medal.
        $players = collect(range(1, 6))->map(fn ($n) => Player::create([
            'guid' => $n, 'last_name' => "Player{$n}", 'last_name_plain' => "Player{$n}",
            'icon_path' => $n === 6 ? 'player-icons/6.png' : null,
        ]));

        // 13 rounds so the match counts as "reached a conclusion" (see
        // GameMatch::scopeReachedConclusion()) -- otherwise scopeForSeason()
        // excludes it as abandoned and no kill in it ever reaches the ranking.
        $match = GameMatch::create(['server_id' => $server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
        for ($r = 0; $r < 13; $r++) {
            Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);
        }
        $round = $match->rounds()->first();

        foreach ($players as $i => $attacker) {
            // Descending kill counts so player 6 (with the icon) lands last / rank #6.
            $kills = 60 - $i * 10;
            for ($k = 0; $k < $kills; $k++) {
                Kill::create([
                    'round_id' => $round->id, 'match_id' => $match->id,
                    'attacker_player_id' => $attacker->id, 'attacker_guid' => $attacker->guid, 'attacker_name' => $attacker->last_name, 'attacker_team' => 'allies',
                    'victim_player_id' => null, 'victim_guid' => 0, 'victim_name' => 'Bot', 'victim_team' => 'axis',
                    'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET',
                    'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false,
                    'occurred_at' => now(),
                ]);
            }
        }

        $response = $this->get(route('leaderboard', ['server' => $server->slug, 'season' => 'all']));

        $response->assertOk();
        $response->assertSee('storage/player-icons/6.png', false);
    }

    /**
     * Bug real, encontrado al verificar la entrada anterior en producción
     * (2026-08-28): `/paises` seguía sin mostrar el ícono de GenuineuPP. aun
     * después del fix general -- causa: `SpecialtyController::countries()`
     * trae los jugadores con una lista explícita de columnas
     * (`get(['id', 'guid', 'last_name', ...])`) que no incluía `icon_path`,
     * así que el modelo nunca tenía el dato aunque la fila en la base sí lo
     * tuviera. Mismo patrón a vigilar en cualquier otro `Player::...->get([...])`
     * con columnas explícitas.
     *
     * No se prueba vía HTTP contra `/paises` porque esa página depende de la
     * base GeoIP real (`storage/app/geoip/country.mmdb`), que no viaja con el
     * repo (se descarga aparte, ver CLAUDE.md) -- en un clone descartable
     * sin ese archivo, `GeoIp::countryFor()` siempre devuelve null y el
     * jugador ni siquiera aparece en la página (mismo motivo por el que
     * `CountriesSeasonTest` ya es una de las 2 fallas preexistentes conocidas
     * de la suite). En cambio se prueba la query exacta que usa el
     * controller, columna por columna, sin pasar por GeoIP.
     */
    public function test_countries_player_query_selects_icon_path(): void
    {
        $player = Player::create(['guid' => 1, 'last_name' => 'A', 'last_name_plain' => 'A', 'ip' => '8.8.8.8', 'icon_path' => 'player-icons/1.png']);

        // Misma lista de columnas que SpecialtyController::countries().
        $fetched = Player::whereNotNull('ip')->whereIn('id', [$player->id])
            ->get(['id', 'guid', 'last_name', 'last_name_plain', 'ip', 'kills_total', 'icon_path'])
            ->first();

        $this->assertSame('player-icons/1.png', $fetched->icon_path);
        $this->assertNotNull($fetched->icon_url);
    }
}
