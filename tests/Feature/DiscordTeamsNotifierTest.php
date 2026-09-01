<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Setting;
use App\Services\DiscordTeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Webhook de Discord para el anuncio de equipos armados en /equipos
 * (2026-08-31, a pedido del dueño) -- separado del webhook de resultados de
 * partidas (discord_match_webhook_url / DiscordMatchNotifier) a proposito,
 * para poder mandarlo a un canal distinto.
 */
class DiscordTeamsNotifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La migracion 2026_08_10_090005_seed_default_server_and_backfill.php ya
     * siembra un server real ("Pug Latam", slug "pug-latam") en cada
     * migrate/RefreshDatabase -- crear uno nuevo con el mismo slug choca con
     * el unique real (mismo gotcha ya documentado en
     * UpdateHostedServerPortsTest::realServer()).
     */
    private function server(): Server
    {
        return Server::first();
    }

    private function player(int $guid, string $name, ?string $rango, float $score = 50.0): object
    {
        return (object) ['guid' => $guid, 'name' => $name, 'rango' => $rango, 'score' => $score];
    }

    public function test_does_nothing_without_a_teams_webhook_configured(): void
    {
        Http::fake();

        $sent = DiscordTeamsNotifier::notify($this->server(), collect([$this->player(1, 'p1', 'A')]), collect([$this->player(2, 'p2', 'B')]));

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_posts_both_rosters_to_the_teams_webhook(): void
    {
        Setting::current()->update(['discord_teams_webhook_url' => 'https://discord.com/api/webhooks/999/xyz']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);

        $axis = collect([$this->player(1, 'axisOne', 'A'), $this->player(2, 'axisTwo', null)]);
        $allies = collect([$this->player(3, 'alliesOne', 'C')]);

        $sent = DiscordTeamsNotifier::notify($this->server(), $axis, $allies);

        $this->assertTrue($sent);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $embed = $request->data()['embeds'][0];
            $axisField = collect($embed['fields'])->firstWhere('name', 'Axis 🔴');
            $alliesField = collect($embed['fields'])->firstWhere('name', 'Allies 🔵');

            return $request->url() === 'https://discord.com/api/webhooks/999/xyz'
                && $axisField['inline'] === true && $alliesField['inline'] === true
                && str_contains($axisField['value'], 'axisOne (A)')
                && str_contains($axisField['value'], 'axisTwo')
                && ! str_contains($axisField['value'], 'axisTwo (')
                && str_contains($alliesField['value'], 'alliesOne (C)');
        });
    }

    /** El nombre del server (ej. "Pug Latam") no debe repetirse con texto fijo de branding, y el score debe salir (mismo dato que ya muestra /equipos). */
    public function test_description_has_server_name_once_and_the_team_score(): void
    {
        Setting::current()->update(['discord_teams_webhook_url' => 'https://discord.com/api/webhooks/999/xyz']);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);

        $axis = collect([$this->player(1, 'a1', 'A', 90.0), $this->player(2, 'a2', 'B', 50.0)]);
        $allies = collect([$this->player(3, 'b1', 'C', 60.0)]);

        DiscordTeamsNotifier::notify($this->server(), $axis, $allies);

        Http::assertSent(function ($request) {
            $description = $request->data()['embeds'][0]['description'];

            return substr_count($description, 'Pug Latam') === 1
                && str_contains($description, '140')
                && str_contains($description, '60');
        });
    }

    public function test_uses_the_teams_webhook_not_the_match_results_one(): void
    {
        Setting::current()->update([
            'discord_match_webhook_url' => 'https://discord.com/api/webhooks/111/match',
            'discord_teams_webhook_url' => 'https://discord.com/api/webhooks/222/teams',
        ]);
        Http::fake(['discord.com/*' => Http::response(['ok' => true], 204)]);

        DiscordTeamsNotifier::notify($this->server(), collect([$this->player(1, 'p1', 'A')]), collect([$this->player(2, 'p2', 'B')]));

        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/webhooks/222/teams');
    }

    public function test_a_failed_discord_response_returns_false(): void
    {
        Setting::current()->update(['discord_teams_webhook_url' => 'https://discord.com/api/webhooks/999/xyz']);
        Http::fake(['discord.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $sent = DiscordTeamsNotifier::notify($this->server(), collect([$this->player(1, 'p1', 'A')]), collect([$this->player(2, 'p2', 'B')]));

        $this->assertFalse($sent);
    }
}
