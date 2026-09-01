<?php

namespace Tests\Feature\Support;

use App\Models\Player;
use App\Models\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteUserPlayerRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_site_user_can_be_linked_to_a_player_and_read_back_both_ways(): void
    {
        $player = Player::create(['guid' => 111, 'last_name' => 'Zhaiks', 'last_name_plain' => 'Zhaiks']);

        $siteUser = SiteUser::create([
            'discord_id' => '123456789012345678',
            'discord_username' => 'zhaiks',
            'player_id' => $player->id,
        ]);

        $this->assertTrue($player->fresh()->siteUser->is($siteUser));
        $this->assertTrue($siteUser->fresh()->player->is($player));
    }

    public function test_has_pending_claim_is_true_only_while_the_code_has_not_expired(): void
    {
        $player = Player::create(['guid' => 222, 'last_name' => 'Otro', 'last_name_plain' => 'Otro']);

        $pending = SiteUser::create([
            'discord_id' => '1', 'discord_username' => 'a',
            'pending_claim_player_id' => $player->id,
            'claim_code' => 'ABCDEFGH',
            'claim_code_expires_at' => now()->addMinutes(15),
        ]);
        $expired = SiteUser::create([
            'discord_id' => '2', 'discord_username' => 'b',
            'pending_claim_player_id' => $player->id,
            'claim_code' => 'ZZZZZZZZ',
            'claim_code_expires_at' => now()->subMinute(),
        ]);
        $none = SiteUser::create(['discord_id' => '3', 'discord_username' => 'c']);

        $this->assertTrue($pending->hasPendingClaim());
        $this->assertFalse($expired->hasPendingClaim());
        $this->assertFalse($none->hasPendingClaim());
    }
}
