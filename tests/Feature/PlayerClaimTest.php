<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_claim_generates_a_code_valid_for_15_minutes(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a']);

        $this->actingAs($siteUser, 'site')
            ->post(route('players.claim.store', $player))
            ->assertRedirect(route('account.show'));

        $siteUser->refresh();
        $this->assertSame($player->id, $siteUser->pending_claim_player_id);
        $this->assertNotNull($siteUser->claim_code);
        $this->assertTrue($siteUser->claim_code_expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
    }

    public function test_a_guest_cannot_start_a_claim(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $this->post(route('players.claim.store', $player))->assertRedirect(route('login'));
    }

    public function test_claiming_a_player_already_claimed_by_someone_else_is_rejected(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        SiteUser::create(['discord_id' => 'owner', 'discord_username' => 'dueño', 'player_id' => $player->id]);
        $siteUser = SiteUser::create(['discord_id' => 'other', 'discord_username' => 'otro']);

        $this->actingAs($siteUser, 'site')->post(route('players.claim.store', $player));

        $this->assertNull($siteUser->fresh()->pending_claim_player_id);
    }

    public function test_a_site_user_who_already_claimed_a_player_cannot_start_a_second_claim(): void
    {
        $claimed = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $other = Player::create(['guid' => 222, 'last_name' => 'Otro', 'last_name_plain' => 'Otro']);
        $siteUser = SiteUser::create(['discord_id' => '1', 'discord_username' => 'a', 'player_id' => $claimed->id]);

        $this->actingAs($siteUser, 'site')->post(route('players.claim.store', $other));

        $this->assertNull($siteUser->fresh()->pending_claim_player_id);
    }

    public function test_canceling_a_pending_claim_clears_it(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);
        $siteUser = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id, 'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($siteUser, 'site')->post(route('account.claim.cancel'));

        $siteUser->refresh();
        $this->assertNull($siteUser->pending_claim_player_id);
        $this->assertNull($siteUser->claim_code);
        $this->assertNull($siteUser->claim_code_expires_at);
    }
}
