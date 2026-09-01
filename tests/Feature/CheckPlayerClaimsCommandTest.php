<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Player;
use App\Models\Server;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckPlayerClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
    }

    private function pendingClaim(int $guid, string $code, \Illuminate\Support\Carbon $expiresAt): SiteUser
    {
        $player = Player::create(['guid' => $guid, 'last_name' => "P{$guid}", 'last_name_plain' => "P{$guid}"]);

        return SiteUser::create([
            'discord_id' => (string) $guid, 'discord_username' => "user{$guid}",
            'pending_claim_player_id' => $player->id, 'claim_code' => $code,
            'claim_code_expires_at' => $expiresAt,
        ]);
    }

    public function test_confirms_a_claim_when_the_code_appears_in_chat_from_the_right_guid(): void
    {
        $siteUser = $this->pendingClaim(111, 'ABCDEFGH', now()->addMinutes(10));
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 111, 'name' => 'P111',
            'message' => 'mi codigo es ABCDEFGH', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims');

        $siteUser->refresh();
        $this->assertNotNull($siteUser->player_id);
        $this->assertNull($siteUser->pending_claim_player_id);
        $this->assertNull($siteUser->claim_code);
        $this->assertNull($siteUser->claim_code_expires_at);
    }

    public function test_does_not_confirm_when_the_code_appears_from_a_different_guid(): void
    {
        $siteUser = $this->pendingClaim(111, 'ABCDEFGH', now()->addMinutes(10));
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 999, 'name' => 'Otro',
            'message' => 'ABCDEFGH', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims');

        $this->assertNull($siteUser->fresh()->player_id);
    }

    public function test_does_not_confirm_an_expired_claim_even_if_the_code_appears(): void
    {
        $siteUser = $this->pendingClaim(111, 'ABCDEFGH', now()->subMinute());
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 111, 'name' => 'P111',
            'message' => 'ABCDEFGH', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims');

        $this->assertNull($siteUser->fresh()->player_id);
    }

    public function test_does_nothing_when_there_are_no_pending_claims(): void
    {
        $this->artisan('players:check-claims')->assertSuccessful();
    }

    public function test_does_not_crash_when_two_pending_claims_target_the_same_player(): void
    {
        // site_users.player_id es unique -- PlayerClaimController::store() solo
        // bloquea contra un jugador YA confirmado a otra cuenta, no contra otro
        // reclamo pendiente sobre el mismo jugador (dos cuentas Discord distintas,
        // o una carrera real). Si ambos codigos aparecen en el chat en la misma
        // corrida, el segundo update() choca con la unique constraint -- el
        // comando no debe abortar por eso.
        $player = Player::create(['guid' => 111, 'last_name' => 'P111', 'last_name_plain' => 'P111']);

        $siteUserA = SiteUser::create([
            'discord_id' => 'a111', 'discord_username' => 'userA',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'CODEAAAA',
            'claim_code_expires_at' => now()->addMinutes(10),
        ]);
        $siteUserB = SiteUser::create([
            'discord_id' => 'b111', 'discord_username' => 'userB',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'CODEBBBB',
            'claim_code_expires_at' => now()->addMinutes(10),
        ]);

        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 111, 'name' => 'P111',
            'message' => 'CODEAAAA', 'occurred_at' => now(),
        ]);
        ChatMessage::create([
            'server_id' => $this->server->id, 'guid' => 111, 'name' => 'P111',
            'message' => 'CODEBBBB', 'occurred_at' => now(),
        ]);

        $this->artisan('players:check-claims')->assertSuccessful();

        $siteUserA->refresh();
        $siteUserB->refresh();

        // Exactamente uno de los dos gano la carrera y quedo confirmado -- el otro
        // no crasheo el comando ni quedo con datos corruptos (ej. player_id de
        // otro jugador, o ambos campos a medio limpiar).
        $confirmed = collect([$siteUserA, $siteUserB])->filter(fn ($u) => $u->player_id === $player->id);
        $this->assertCount(1, $confirmed);

        $loser = $confirmed->isEmpty() ? null : collect([$siteUserA, $siteUserB])->first(fn ($u) => $u->id !== $confirmed->first()->id);
        $this->assertNotNull($loser);
        $this->assertNull($loser->player_id);
        $this->assertSame($player->id, $loser->pending_claim_player_id);
    }
}
