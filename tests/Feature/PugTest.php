<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Pug;
use App\Models\Round;
use App\Models\Server;
use App\Models\SiteUser;
use App\Support\PugManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PugTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // La migracion semilla ya crea el server "pug-latam"; crear otro con el mismo
        // slug choca (mismo gotcha que UpdateHostedServerPortsTest).
        $this->server = Server::first();
    }

    /** Un jugador real + su cuenta de Discord con el perfil ya reclamado. */
    private function player(int $guid, string $name): Player
    {
        return Player::create(['guid' => $guid, 'last_name' => $name, 'last_name_plain' => $name]);
    }

    private function siteUserFor(Player $player, string $discordId): SiteUser
    {
        return SiteUser::create([
            'discord_id' => $discordId,
            'discord_username' => $player->last_name_plain,
            'player_id' => $player->id,
        ]);
    }

    /** El objeto que devuelve TeamBalancer::suggest(), con lo minimo que usa el pug. */
    private function teamBalance(array $teamA, array $teamB): object
    {
        $map = fn (array $players) => collect($players)->map(
            fn (Player $p) => (object) ['guid' => $p->guid, 'name' => $p->last_name]
        );

        return (object) ['enough' => true, 'teamA' => $map($teamA), 'teamB' => $map($teamB)];
    }

    private function startedPug(): array
    {
        $a1 = $this->player(101, 'A1');
        $b1 = $this->player(201, 'B1');
        $pug = PugManager::start($this->server, $this->teamBalance([$a1], [$b1]));

        return [$pug, $this->siteUserFor($a1, '1'), $this->siteUserFor($b1, '2')];
    }

    public function test_starting_a_pug_freezes_the_teams(): void
    {
        [$pug] = $this->startedPug();

        $this->assertSame(Pug::STATUS_AWAITING_CAPTAINS, $pug->status);
        $this->assertSame([101], $pug->teamGuids('A'));
        $this->assertSame([201], $pug->teamGuids('B'));
    }

    public function test_only_one_pug_can_be_open_per_server(): void
    {
        $this->startedPug();

        $this->expectException(RuntimeException::class);
        PugManager::start($this->server, $this->teamBalance([$this->player(102, 'x')], [$this->player(202, 'y')]));
    }

    public function test_a_player_can_only_captain_the_team_they_play_in(): void
    {
        [$pug, $captainA] = $this->startedPug();

        $this->expectExceptionMessage('Solo podés ser capitán del equipo en el que estás jugando.');
        PugManager::claimCaptain($pug, $captainA, 'B');
    }

    public function test_a_site_user_without_a_claimed_profile_cannot_be_captain(): void
    {
        [$pug] = $this->startedPug();
        $sinReclamar = SiteUser::create(['discord_id' => '99', 'discord_username' => 'x']);

        $this->expectExceptionMessage('Necesitás reclamar tu perfil de jugador antes de ser capitán.');
        PugManager::claimCaptain($pug, $sinReclamar, 'A');
    }

    public function test_the_veto_starts_once_both_captains_claimed(): void
    {
        [$pug, $captainA, $captainB] = $this->startedPug();

        PugManager::claimCaptain($pug, $captainA, 'A');
        $this->assertSame(Pug::STATUS_AWAITING_CAPTAINS, $pug->fresh()->status);

        PugManager::claimCaptain($pug, $captainB, 'B');

        $pug->refresh();
        $this->assertSame(Pug::STATUS_VETO, $pug->status);
        $this->assertNotEmpty($pug->veto_pool);
        $this->assertContains($pug->first_turn_team, ['A', 'B']);
    }

    public function test_a_captain_cannot_ban_out_of_turn(): void
    {
        [$pug, $captainA, $captainB] = $this->startedPug();
        PugManager::claimCaptain($pug, $captainA, 'A');
        PugManager::claimCaptain($pug, $captainB, 'B');
        $pug->refresh();

        // El que NO tiene el primer turno.
        $outOfTurn = $pug->first_turn_team === 'A' ? $captainB : $captainA;

        $this->expectExceptionMessage('No es tu turno.');
        PugManager::ban($pug, $outOfTurn, $pug->remainingMaps()[0]);
    }

    public function test_banning_alternates_turns_and_finishes_leaving_the_maps_to_play(): void
    {
        [$pug, $captainA, $captainB] = $this->startedPug();
        PugManager::claimCaptain($pug, $captainA, 'A');
        PugManager::claimCaptain($pug, $captainB, 'B');
        $pug->refresh();

        $captains = ['A' => $captainA, 'B' => $captainB];
        $target = $pug->targetMapCount();

        while (! $pug->vetoIsComplete()) {
            $turn = $pug->currentTurnTeam();
            PugManager::ban($pug, $captains[$turn], $pug->remainingMaps()[0]);
            $pug->refresh();
        }

        $this->assertSame(Pug::STATUS_PLAYING, $pug->status);
        $this->assertCount($target, $pug->maps);
        $this->assertSame($pug->maps[0], $pug->currentMap());
    }

    /**
     * El marcador se DERIVA de las partidas cruzando el roster ganador contra el
     * snapshot de equipos -- no hay contadores que mantener sincronizados.
     */
    public function test_the_session_scoreboard_is_derived_from_the_linked_matches(): void
    {
        [$pug] = $this->startedPug();

        $this->matchWonBy($pug, 101); // gana A
        $this->matchWonBy($pug, 101); // gana A
        $this->matchWonBy($pug, 201); // gana B

        $this->assertSame(['A' => 2, 'B' => 1], $pug->scoreboard());
    }

    public function test_a_match_without_a_decidable_winner_counts_for_nobody(): void
    {
        [$pug] = $this->startedPug();

        $match = GameMatch::create([
            'server_id' => $this->server->id, 'pug_id' => $pug->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(),
        ]);
        // Rondas sin winner_guids: clusterRoundWinners() no puede decidir.
        for ($i = 0; $i < 13; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(), 'ended_at' => now(),
            ]);
        }

        $this->assertSame(['A' => 0, 'B' => 0], $pug->scoreboard());
    }

    /**
     * Renderiza el partial en sus tres estados. Se hace sobre la vista y no sobre
     * la ruta /equipos a proposito: esa llama a RCON de verdad. Es el test que
     * faltaba en el bug del contador de la galeria (2026-09-05), donde el endpoint
     * andaba y sus tests pasaban, pero el HTML que lo invocaba estaba roto.
     */
    public function test_the_pug_panel_renders_in_every_state(): void
    {
        [$pug, $captainA, $captainB] = $this->startedPug();

        $render = fn () => view('partials.pug', [
            'pug' => $pug->fresh(),
            'server' => $this->server,
            'teamBalance' => $this->teamBalance([], []),
        ])->render();

        // Logueado como uno de los jugadores: es el unico caso donde aparece el
        // boton de postularse (sin sesion muestra el link a iniciar sesion).
        $this->actingAs($captainA, 'site');
        $this->assertStringContainsString('Ser capitán', $render());

        PugManager::claimCaptain($pug, $captainA, 'A');
        PugManager::claimCaptain($pug, $captainB, 'B');
        // Texto fijo del veto: quien tiene el primer turno se sortea, asi que
        // asertar sobre "es tu turno" seria inestable.
        $this->assertStringContainsString('se banea hasta quedar', $render());

        $pug->refresh();
        $captains = ['A' => $captainA, 'B' => $captainB];
        while (! $pug->vetoIsComplete()) {
            PugManager::ban($pug, $captains[$pug->currentTurnTeam()], $pug->remainingMaps()[0]);
            $pug->refresh();
        }

        $this->assertStringContainsString('Mapas de la noche', $render());
    }

    /** Sin pug abierto el panel ofrece abrir uno, no explota. */
    public function test_the_panel_offers_to_start_a_pug_when_there_is_none(): void
    {
        $html = view('partials.pug', [
            'pug' => null,
            'server' => $this->server,
            'teamBalance' => $this->teamBalance([$this->player(1, 'a')], [$this->player(2, 'b')]),
        ])->render();

        $this->assertStringContainsString('Iniciar pug', $html);
    }

    private function matchWonBy(Pug $pug, int $winnerGuid): void
    {
        $match = GameMatch::create([
            'server_id' => $this->server->id, 'pug_id' => $pug->id,
            'map' => 'mp_toujane_fix', 'gametype' => 'sd', 'started_at' => now(),
        ]);

        for ($i = 0; $i < 13; $i++) {
            Round::create([
                'server_id' => $this->server->id, 'match_id' => $match->id,
                'map' => 'mp_toujane_fix', 'gametype' => 'sd',
                'started_at' => now(), 'ended_at' => now(),
            ])->update(['winner_guids' => [$winnerGuid]]);
        }
    }
}
