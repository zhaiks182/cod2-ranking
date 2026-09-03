<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\ClanInvitation;
use App\Models\ClanMember;
use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\Player;
use App\Models\Round;
use App\Models\Server;
use App\Models\SiteUser;
use App\Support\ClanStatsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modulo de clanes (2026-09-03) -- ver docs/superpowers/specs/
 * 2026-09-03-clanes-design.md. Identidad + membresia + estadisticas reales
 * de los miembros, sin ladders/torneos.
 */
class ClanTest extends TestCase
{
    use RefreshDatabase;

    private function makeSiteUser(int $discordId, bool $claimed = true): SiteUser
    {
        $playerId = null;
        if ($claimed) {
            $player = Player::create(['guid' => $discordId, 'last_name' => "P{$discordId}", 'last_name_plain' => "P{$discordId}"]);
            $playerId = $player->id;
        }

        return SiteUser::create(['discord_id' => (string) $discordId, 'discord_username' => "user{$discordId}", 'player_id' => $playerId]);
    }

    private function makeClan(SiteUser $founder, string $tag = 'TAG'): Clan
    {
        $clan = Clan::create(['name' => "Clan {$tag}", 'tag' => $tag, 'founder_site_user_id' => $founder->id]);
        ClanMember::create(['clan_id' => $clan->id, 'site_user_id' => $founder->id, 'role' => 'founder', 'joined_at' => now()]);

        return $clan;
    }

    private function addMember(Clan $clan, SiteUser $siteUser, string $role = 'member'): ClanMember
    {
        return ClanMember::create(['clan_id' => $clan->id, 'site_user_id' => $siteUser->id, 'role' => $role, 'joined_at' => now()]);
    }

    // -- Paginas (renderizado) -------------------------------------------

    public function test_index_page_renders(): void
    {
        $founder = $this->makeSiteUser(1);
        $this->makeClan($founder, 'DEST');

        $this->get(route('clans.index'))->assertOk()->assertSee('DEST');
    }

    public function test_create_page_renders_for_a_claimed_user(): void
    {
        $siteUser = $this->makeSiteUser(1);

        $this->actingAs($siteUser, 'site')->get(route('clans.create'))->assertOk();
    }

    public function test_show_page_renders_for_a_guest(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');

        $this->get(route('clans.show', $clan))->assertOk()->assertSee('DEST');
    }

    /** Ejercita las secciones solo-manager (invitar, solicitudes, editar). */
    public function test_show_page_renders_for_a_manager_with_a_pending_request_and_a_sent_invite(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $applicant = $this->makeSiteUser(2);
        $this->actingAs($applicant, 'site')->post(route('clans.request', $clan));
        $target = $this->makeSiteUser(3);
        $this->actingAs($founder, 'site')->post(route('clans.invite', $clan), ['site_user_id' => $target->id]);

        $this->actingAs($founder, 'site')->get(route('clans.show', $clan))
            ->assertOk()
            ->assertSee(__('Solicitudes pendientes'))
            ->assertSee(__('Invitaciones enviadas, pendientes de respuesta'));
    }

    public function test_search_invitable_only_returns_claimed_players_without_a_clan(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');

        // Los 3 candidatos comparten "Searchable" en el nombre para que la
        // misma query los alcance a todos -- solo $available debe volver.
        $unclaimedPlayer = Player::create(['guid' => 900, 'last_name' => 'SearchableUnclaimed', 'last_name_plain' => 'SearchableUnclaimed']);
        SiteUser::create(['discord_id' => '900', 'discord_username' => 'u900']); // sin player_id

        $inClanPlayer = Player::create(['guid' => 901, 'last_name' => 'SearchableInClan', 'last_name_plain' => 'SearchableInClan']);
        $inClanSiteUser = SiteUser::create(['discord_id' => '901', 'discord_username' => 'u901', 'player_id' => $inClanPlayer->id]);
        $this->makeClan($inClanSiteUser, 'OTHER');

        $availablePlayer = Player::create(['guid' => 902, 'last_name' => 'SearchableAvailable', 'last_name_plain' => 'SearchableAvailable']);
        SiteUser::create(['discord_id' => '902', 'discord_username' => 'u902', 'player_id' => $availablePlayer->id]);

        $response = $this->actingAs($founder, 'site')
            ->getJson(route('clans.search-invitable', $clan).'?q=Searchable');

        $names = collect($response->json())->pluck('name');
        $this->assertSame(['SearchableAvailable'], $names->all());
    }

    // -- Creacion -----------------------------------------------------

    public function test_creating_a_clan_requires_a_claimed_profile(): void
    {
        $siteUser = $this->makeSiteUser(1, claimed: false);

        $this->actingAs($siteUser, 'site')
            ->post(route('clans.store'), ['name' => 'Destino', 'tag' => 'DEST'])
            ->assertForbidden();

        $this->assertDatabaseCount('clans', 0);
    }

    public function test_a_claimed_user_can_create_a_clan_and_becomes_founder(): void
    {
        $siteUser = $this->makeSiteUser(1);

        $this->actingAs($siteUser, 'site')
            ->post(route('clans.store'), ['name' => 'Destino', 'tag' => 'DEST'])
            ->assertRedirect();

        $clan = Clan::where('tag', 'DEST')->first();
        $this->assertNotNull($clan);
        $this->assertSame('founder', ClanMember::where('clan_id', $clan->id)->where('site_user_id', $siteUser->id)->first()->role);
    }

    public function test_clan_name_and_tag_must_be_unique(): void
    {
        $founder = $this->makeSiteUser(1);
        $this->makeClan($founder, 'DEST');
        $other = $this->makeSiteUser(2);

        $this->actingAs($other, 'site')
            ->post(route('clans.store'), ['name' => 'Otro', 'tag' => 'DEST'])
            ->assertSessionHasErrors('tag');
    }

    public function test_a_user_already_in_a_clan_cannot_create_another(): void
    {
        $founder = $this->makeSiteUser(1);
        $this->makeClan($founder, 'DEST');

        $this->actingAs($founder, 'site')
            ->post(route('clans.store'), ['name' => 'Segundo', 'tag' => 'SEG'])
            ->assertSessionHasErrors('clan');

        $this->assertDatabaseCount('clans', 1);
    }

    // -- Union por solicitud --------------------------------------------

    public function test_player_can_request_to_join_and_founder_can_approve(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $applicant = $this->makeSiteUser(2);

        $this->actingAs($applicant, 'site')->post(route('clans.request', $clan))->assertRedirect();

        $invitation = ClanInvitation::where('clan_id', $clan->id)->where('site_user_id', $applicant->id)->first();
        $this->assertSame('player_requested', $invitation->direction);
        $this->assertSame('pending', $invitation->status);

        $this->actingAs($founder, 'site')
            ->post(route('clans.requests.respond', [$clan, $invitation]), ['accept' => 1])
            ->assertRedirect();

        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertSame('member', ClanMember::where('site_user_id', $applicant->id)->first()->role);
    }

    public function test_founder_can_reject_a_join_request(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $applicant = $this->makeSiteUser(2);
        $this->actingAs($applicant, 'site')->post(route('clans.request', $clan));
        $invitation = ClanInvitation::first();

        $this->actingAs($founder, 'site')->post(route('clans.requests.respond', [$clan, $invitation]), ['accept' => 0]);

        $this->assertSame('rejected', $invitation->fresh()->status);
        $this->assertNull(ClanMember::where('site_user_id', $applicant->id)->first());
    }

    // -- Union por invitacion --------------------------------------------

    public function test_manager_can_invite_a_player_and_the_player_can_accept(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $target = $this->makeSiteUser(2);

        $this->actingAs($founder, 'site')
            ->post(route('clans.invite', $clan), ['site_user_id' => $target->id])
            ->assertRedirect();

        $invitation = ClanInvitation::where('site_user_id', $target->id)->first();
        $this->assertSame('manager_invited', $invitation->direction);

        $this->actingAs($target, 'site')
            ->post(route('clans.invitations.respond', $invitation), ['accept' => 1])
            ->assertRedirect();

        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertSame('member', ClanMember::where('site_user_id', $target->id)->first()->role);
    }

    public function test_player_can_reject_an_invitation(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $target = $this->makeSiteUser(2);
        $this->actingAs($founder, 'site')->post(route('clans.invite', $clan), ['site_user_id' => $target->id]);
        $invitation = ClanInvitation::first();

        $this->actingAs($target, 'site')->post(route('clans.invitations.respond', $invitation), ['accept' => 0]);

        $this->assertSame('rejected', $invitation->fresh()->status);
        $this->assertNull(ClanMember::where('site_user_id', $target->id)->first());
    }

    public function test_a_player_already_in_a_clan_cannot_request_to_join_another(): void
    {
        $founderA = $this->makeSiteUser(1);
        $this->makeClan($founderA, 'AAA');
        $founderB = $this->makeSiteUser(2);
        $clanB = $this->makeClan($founderB, 'BBB');

        $this->actingAs($founderA, 'site')
            ->post(route('clans.request', $clanB))
            ->assertSessionHasErrors('clan');
    }

    // -- Permisos por rol -------------------------------------------------

    public function test_a_plain_member_cannot_invite_approve_or_kick(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $member = $this->makeSiteUser(2);
        $this->addMember($clan, $member, 'member');
        $victim = $this->makeSiteUser(3);
        $this->addMember($clan, $victim, 'member');

        $this->actingAs($member, 'site')->post(route('clans.invite', $clan), ['site_user_id' => $victim->id])->assertForbidden();

        $applicant = $this->makeSiteUser(4);
        $this->actingAs($applicant, 'site')->post(route('clans.request', $clan));
        $invitation = ClanInvitation::first();
        $this->actingAs($member, 'site')->post(route('clans.requests.respond', [$clan, $invitation]), ['accept' => 1])->assertForbidden();

        $victimMember = ClanMember::where('site_user_id', $victim->id)->first();
        $this->actingAs($member, 'site')->delete(route('clans.members.kick', [$clan, $victimMember]))->assertForbidden();
    }

    public function test_a_manager_can_kick_members_but_not_another_manager_or_the_founder(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $manager = $this->makeSiteUser(2);
        $this->addMember($clan, $manager, 'manager');
        $otherManager = $this->makeSiteUser(3);
        $otherManagerMember = $this->addMember($clan, $otherManager, 'manager');
        $plainMember = $this->makeSiteUser(4);
        $plainMemberRow = $this->addMember($clan, $plainMember, 'member');
        $founderMember = ClanMember::where('site_user_id', $founder->id)->first();

        $this->actingAs($manager, 'site')->delete(route('clans.members.kick', [$clan, $plainMemberRow]))->assertRedirect();
        $this->assertNull(ClanMember::find($plainMemberRow->id));

        $this->actingAs($manager, 'site')->delete(route('clans.members.kick', [$clan, $otherManagerMember]))->assertForbidden();
        $this->actingAs($manager, 'site')->delete(route('clans.members.kick', [$clan, $founderMember]))->assertForbidden();
    }

    public function test_only_the_founder_can_change_roles(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $manager = $this->makeSiteUser(2);
        $this->addMember($clan, $manager, 'manager');
        $member = $this->makeSiteUser(3);
        $memberRow = $this->addMember($clan, $member, 'member');

        $this->actingAs($manager, 'site')->post(route('clans.members.role', [$clan, $memberRow]), ['role' => 'manager'])->assertForbidden();

        $this->actingAs($founder, 'site')->post(route('clans.members.role', [$clan, $memberRow]), ['role' => 'manager'])->assertRedirect();
        $this->assertSame('manager', $memberRow->fresh()->role);
    }

    public function test_only_the_founder_can_transfer_or_disband(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $manager = $this->makeSiteUser(2);
        $managerRow = $this->addMember($clan, $manager, 'manager');

        $this->actingAs($manager, 'site')->post(route('clans.transfer', $clan), ['member_id' => $managerRow->id])->assertForbidden();
        $this->actingAs($manager, 'site')->delete(route('clans.disband', $clan))->assertForbidden();
    }

    // -- Transferencia / salida / disolucion -------------------------------

    public function test_transferring_founder_swaps_roles_correctly(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $manager = $this->makeSiteUser(2);
        $managerRow = $this->addMember($clan, $manager, 'manager');
        $founderRow = ClanMember::where('site_user_id', $founder->id)->first();

        $this->actingAs($founder, 'site')->post(route('clans.transfer', $clan), ['member_id' => $managerRow->id])->assertRedirect();

        $this->assertSame('founder', $managerRow->fresh()->role);
        $this->assertSame('member', $founderRow->fresh()->role);
        $this->assertSame($manager->id, $clan->fresh()->founder_site_user_id);
    }

    public function test_founder_cannot_leave_without_transferring_first(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');

        $this->actingAs($founder, 'site')->post(route('clans.leave', $clan))->assertSessionHasErrors('clan');

        $this->assertNotNull(ClanMember::where('site_user_id', $founder->id)->first());
    }

    public function test_disbanding_deletes_the_clan_its_members_and_its_invitations(): void
    {
        $founder = $this->makeSiteUser(1);
        $clan = $this->makeClan($founder, 'DEST');
        $member = $this->makeSiteUser(2);
        $this->addMember($clan, $member, 'member');
        $applicant = $this->makeSiteUser(3);
        $this->actingAs($applicant, 'site')->post(route('clans.request', $clan));

        $this->actingAs($founder, 'site')->delete(route('clans.disband', $clan))->assertRedirect();

        $this->assertDatabaseCount('clans', 0);
        $this->assertDatabaseCount('clan_members', 0);
        $this->assertDatabaseCount('clan_invitations', 0);
    }

    // -- Estadisticas -------------------------------------------------------

    public function test_clan_stats_aggregate_real_kills_of_current_members(): void
    {
        $server = Server::create([
            'name' => 'Test Server', 'slug' => 'test-server', 'log_path' => '/tmp/x.log',
            'rcon_host' => '127.0.0.1', 'rcon_port' => 28960, 'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1', 'connect_port' => 28960, 'max_clients' => 30, 'is_active' => true,
        ]);
        $memberPlayer = Player::create(['guid' => 100, 'last_name' => 'M', 'last_name_plain' => 'M']);
        $outsider = Player::create(['guid' => 200, 'last_name' => 'O', 'last_name_plain' => 'O']);
        // Sin ended_at (partida "en curso") -- GameMatch::forSeason() excluye
        // via abandonedWithoutConclusion() cualquier partida CON ended_at que
        // no llego a 13 rondas ni a un evento match_end (ver GameMatch.php),
        // y este fixture solo arma 1 ronda.
        $season = \App\Models\Season::current();
        $match = GameMatch::create(['server_id' => $server->id, 'season_id' => $season->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => null]);
        $round = Round::create(['server_id' => $server->id, 'match_id' => $match->id, 'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now()]);

        // 2 kills reales del miembro del clan, 1 muerte suya -- kd = 2.0.
        for ($i = 0; $i < 2; $i++) {
            Kill::create([
                'round_id' => $round->id, 'match_id' => $match->id,
                'attacker_player_id' => $memberPlayer->id, 'attacker_guid' => $memberPlayer->guid, 'attacker_name' => 'M', 'attacker_team' => 'allies',
                'victim_player_id' => $outsider->id, 'victim_guid' => $outsider->guid, 'victim_name' => 'O', 'victim_team' => 'axis',
                'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
                'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
            ]);
        }
        Kill::create([
            'round_id' => $round->id, 'match_id' => $match->id,
            'attacker_player_id' => $outsider->id, 'attacker_guid' => $outsider->guid, 'attacker_name' => 'O', 'attacker_team' => 'axis',
            'victim_player_id' => $memberPlayer->id, 'victim_guid' => $memberPlayer->guid, 'victim_name' => 'M', 'victim_team' => 'allies',
            'weapon' => 'weapon_mp44', 'damage' => 100, 'mod' => 'MOD_RIFLE_BULLET', 'hitloc' => 'torso_upper',
            'is_headshot' => false, 'is_grenade' => false, 'is_suicide' => false, 'is_teamkill' => false, 'occurred_at' => now(),
        ]);

        $stats = ClanStatsCalculator::aggregate([$memberPlayer->id]);

        $this->assertSame(2, $stats->kills);
        $this->assertSame(1, $stats->deaths);
        $this->assertSame(2.0, $stats->kd);
        $this->assertSame(1, $stats->matches);
    }

    public function test_clan_stats_ignore_kills_from_players_who_are_not_current_members(): void
    {
        $stats = ClanStatsCalculator::aggregate([]);

        $this->assertSame(0, $stats->kills);
        $this->assertSame(0, $stats->deaths);
        $this->assertSame(0.0, $stats->kd);
        $this->assertSame(0, $stats->matches);
    }
}
