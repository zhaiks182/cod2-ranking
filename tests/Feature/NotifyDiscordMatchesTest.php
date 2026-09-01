<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\LogParserState;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Webhook de Discord con resultados de partida (2026-08-31, a pedido del
 * dueño) -- cod2:notify-discord-matches postea cada partida SD real que
 * termino desde la ultima corrida, una sola vez (discord_notified_at).
 *
 * Reescrito 2026-09-01 tras un bug real en producción: el dueño reportó una
 * notificación de Toujane con "Duración: 0 min" -- confirmado que
 * discord_notified_at se estaba poniendo 2 segundos después de started_at
 * (la partida recién arrancaba). Causa: scopeVisibleInListing() también
 * matchea partidas EN CURSO (`ended_at IS NULL`), pensado para el listado
 * público (que sí debe mostrar partidas en vivo), no para "ya terminó,
 * notificala". Fix: GameMatch::scopeReadyToNotify() (reachedConclusion() +
 * ya no es la partida actual del parser). Ver esa misma fecha en CLAUDE.md
 * para el detalle completo, incluida la ráfaga real de 61 partidas
 * históricas notificadas de golpe la primera vez que se cargó la URL del
 * webhook (fix: filtro `ended_at >= now()->subHours(2)`).
 */
class NotifyDiscordMatchesTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;
    private Player $winner;
    private Player $loser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $this->winner = Player::create(['guid' => 111, 'last_name' => 'Winner', 'last_name_plain' => 'Winner']);
        $this->loser = Player::create(['guid' => 222, 'last_name' => 'Loser', 'last_name_plain' => 'Loser']);
    }

    /**
     * Partida real: 13 rondas ganadas + evento match_end (reachedConclusion()
     * la considera "terminó de verdad"). $kill permite variar la baja de la
     * última ronda (headshot/granada) para probar esos campos del embed.
     */
    private function realMatch(string $gametype = 'sd', bool $backfilled = false, array $kill = []): GameMatch
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => $gametype,
            'started_at' => now()->subMinutes(20), 'ended_at' => now(),
        ]);
        // is_backfilled no esta en $fillable (a proposito -- en produccion solo lo
        // toca el script historico de backfill una vez, nunca create()/update() del
        // codigo de la app), asi que hay que forzarlo aparte para probar el filtro.
        if ($backfilled) {
            $match->forceFill(['is_backfilled' => true])->save();
        }

        for ($i = 1; $i <= 13; $i++) {
            $round = Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => $gametype,
                'started_at' => now()->subMinutes(20), 'ended_at' => now(), 'winner_guids' => [$this->winner->guid],
            ]);
        }

        // El evento match_end tiene que colgar de la ULTIMA ronda -- clusterRoundWinners()
        // recorta las rondas a las que tengan id <= la del match_end, asi que colgarlo
        // de la primera ronda (bug real que tuvo este mismo fixture) deja solo 1 ronda
        // visible y clusterRoundWinners() devuelve null (necesita al menos 2).
        Kill::create(array_merge([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $this->winner->id, 'attacker_guid' => $this->winner->guid, 'attacker_name' => 'Winner', 'attacker_team' => 'allies',
            'victim_player_id' => $this->loser->id, 'victim_guid' => $this->loser->guid, 'victim_name' => 'Loser', 'victim_team' => 'axis',
            'weapon' => 'weapon_mp44', 'mod' => 'MOD_RIFLE_BULLET', 'damage' => 100,
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ], $kill));

        MatchEvent::create(['server_id' => $this->server->id, 'match_id' => $match->id, 'round_id' => $round->id, 'event_type' => 'match_end', 'occurred_at' => now()]);

        return $match;
    }

    public function test_does_nothing_without_a_webhook_configured(): void
    {
        Http::fake();
        $this->realMatch();

        $this->artisan('cod2:notify-discord-matches')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_posts_a_real_finished_sd_match_and_marks_it_notified(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $match = $this->realMatch();

        $this->artisan('cod2:notify-discord-matches')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($match) {
            $embed = $request->data()['embeds'][0];
            $fieldNames = collect($embed['fields'])->pluck('value', 'name');

            return $request->url() === 'https://discord.com/api/webhooks/123/abc'
                && str_contains($embed['title'], 'Toujane')
                && str_contains($fieldNames['Marcador'], '13-0')
                && str_contains($fieldNames['Servidor'], 'Test Server')
                && str_contains($fieldNames['🏆 MVP'], 'Winner')
                && ! isset($fieldNames['🎯 Headshots'])
                && ! isset($fieldNames['💣 Granadas']);
        });
        $this->assertNotNull($match->fresh()->discord_notified_at);
    }

    public function test_shows_the_headshot_and_grenade_leaders_when_the_deciding_kill_has_them(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $this->realMatch(kill: ['is_headshot' => true, 'is_grenade' => true, 'mod' => 'MOD_GRENADE']);

        $this->artisan('cod2:notify-discord-matches');

        Http::assertSent(function ($request) {
            $embed = $request->data()['embeds'][0];
            $fieldNames = collect($embed['fields'])->pluck('value', 'name');

            return str_contains($fieldNames['🎯 Headshots'], 'Winner (1)')
                && str_contains($fieldNames['💣 Granadas'], 'Winner (1)');
        });
    }

    public function test_does_not_post_the_same_match_twice(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $this->realMatch();

        $this->artisan('cod2:notify-discord-matches');
        $this->artisan('cod2:notify-discord-matches');

        Http::assertSentCount(1);
    }

    public function test_does_not_post_a_non_sd_match(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $this->realMatch(gametype: 'dm');

        $this->artisan('cod2:notify-discord-matches');

        Http::assertNothingSent();
    }

    public function test_does_not_post_a_backfilled_match(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $this->realMatch(backfilled: true);

        $this->artisan('cod2:notify-discord-matches');

        Http::assertNothingSent();
    }

    public function test_does_not_post_an_abandoned_match_without_a_real_conclusion(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);

        // Solo 3 rondas, sin evento match_end -- nunca llego a un resultado real.
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now()->subMinutes(20), 'ended_at' => now(),
        ]);
        for ($i = 1; $i <= 3; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
                'started_at' => now(), 'ended_at' => now(), 'winner_guids' => [$this->winner->guid],
            ]);
        }

        $this->artisan('cod2:notify-discord-matches');

        Http::assertNothingSent();
    }

    /**
     * Reproduce el bug real reportado: una partida recién creada, 0 rondas
     * jugadas, sin match_end -- nunca debería notificarse (esto YA lo cubría
     * el test de arriba con 3 rondas, pero el bug real ocurrió con 0 rondas
     * y `ended_at` recién puesto = "started" hace 2 segundos).
     */
    public function test_does_not_post_a_match_that_just_started(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);

        GameMatch::create([
            'server_id' => $this->server->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd',
            'started_at' => now(), 'ended_at' => now(),
        ]);

        $this->artisan('cod2:notify-discord-matches');

        Http::assertNothingSent();
    }

    /**
     * Una partida que va a overtime puede tener 13+ rondas reales y seguir
     * jugándose (el parser todavía la sigue como `current_match_id`) --
     * reachedConclusion() sola ya daría true acá, así que hace falta el
     * chequeo de "ya no es la partida actual" para no notificarla en pleno
     * overtime.
     */
    public function test_does_not_post_a_match_still_being_tracked_as_current_even_with_13_rounds(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $match = $this->realMatch();

        LogParserState::create([
            'server_id' => $this->server->id, 'log_path' => $this->server->log_path,
            'byte_offset' => 0, 'current_match_id' => $match->id,
        ]);

        $this->artisan('cod2:notify-discord-matches');

        Http::assertNothingSent();
        $this->assertNull($match->fresh()->discord_notified_at);
    }

    /**
     * Ráfaga real (2026-08-31): al cargar por primera vez la URL del
     * webhook, decenas de partidas históricas con discord_notified_at aún
     * null se mandaron todas de golpe. Corte de seguridad: solo partidas
     * terminadas en las últimas 2 horas, sin importar qué tan vieja sea la
     * primera corrida real del comando.
     */
    public function test_does_not_flood_old_historical_matches_when_the_webhook_is_first_configured(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);
        $match = $this->realMatch();
        $match->update(['ended_at' => now()->subDays(5)]);

        $this->artisan('cod2:notify-discord-matches');

        Http::assertNothingSent();
        $this->assertNull($match->fresh()->discord_notified_at);
    }

    public function test_a_failed_discord_response_does_not_mark_the_match_as_notified(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['error' => 'bad request'], 400)]);
        $match = $this->realMatch();

        $this->artisan('cod2:notify-discord-matches');

        $this->assertNull($match->fresh()->discord_notified_at);
    }
}
