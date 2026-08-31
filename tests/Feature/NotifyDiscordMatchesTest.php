<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Kill;
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

    /** Partida real: 13 rondas ganadas + evento match_end (scopeVisibleInListing la considera "termino de verdad"). */
    private function realMatch(string $gametype = 'sd', bool $backfilled = false): GameMatch
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
        Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $this->winner->id, 'attacker_guid' => $this->winner->guid, 'attacker_name' => 'Winner', 'attacker_team' => 'allies',
            'victim_player_id' => $this->loser->id, 'victim_guid' => $this->loser->guid, 'victim_name' => 'Loser', 'victim_team' => 'axis',
            'weapon' => 'weapon_mp44', 'mod' => 'MOD_RIFLE_BULLET', 'damage' => 100,
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

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

            return $request->url() === 'https://discord.com/api/webhooks/123/abc'
                && str_contains($embed['title'], 'Toujane')
                && str_contains($embed['fields'][0]['value'], '13-0')
                && str_contains($embed['fields'][2]['value'], 'Winner');
        });
        $this->assertNotNull($match->fresh()->discord_notified_at);
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

    public function test_a_failed_discord_response_does_not_mark_the_match_as_notified(): void
    {
        Setting::current()->update(['discord_match_webhook_url' => 'https://discord.com/api/webhooks/123/abc']);
        Http::fake(['discord.com/*' => Http::response(['error' => 'bad request'], 400)]);
        $match = $this->realMatch();

        $this->artisan('cod2:notify-discord-matches');

        $this->assertNull($match->fresh()->discord_notified_at);
    }
}
