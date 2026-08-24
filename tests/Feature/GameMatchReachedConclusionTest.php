<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchEvent;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un jugador reportó (2026-08-24) que una partida real -- pasó el ready-up,
 * tuvo rondas y kills de verdad -- pero se abandonó antes de llegar a un
 * resultado real (13 rondas ganadas o el evento MatchEnd; del log) y aun así
 * apareció en el listado de partidas como si fuera una partida terminada
 * cualquiera. Confirmado con el dueño: solo debe listarse una partida cuando
 * "llega a un resultado real (13 rondas o evento MatchEnd)".
 */
class GameMatchReachedConclusionTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'Test Server',
            'slug' => 'test-server',
            'log_path' => '/tmp/games_mp.log',
            'rcon_host' => '127.0.0.1',
            'rcon_port' => 28960,
            'rcon_password' => 'test',
            'connect_ip' => '127.0.0.1',
            'connect_port' => 28960,
            'max_clients' => 30,
            'is_active' => true,
        ]);
    }

    private function makeMatch(Server $server): GameMatch
    {
        return GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
        ]);
    }

    private function addRounds(GameMatch $match, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Round::create([
                'server_id' => $match->server_id,
                'match_id' => $match->id,
                'map' => $match->map,
                'gametype' => $match->gametype,
                'started_at' => now(),
                'winner_guids' => ['ABC123'],
            ]);
        }
    }

    public function test_ended_match_abandoned_after_one_round_is_hidden_from_listings(): void
    {
        $server = $this->makeServer();
        $match = $this->makeMatch($server);
        $this->addRounds($match, 1);
        $match->update(['ended_at' => now()]);

        $this->assertSame(0, GameMatch::reachedConclusion()->count());
        $this->assertSame(0, GameMatch::visibleInListing()->count());
        $this->assertFalse($match->fresh()->events()->exists());
    }

    public function test_still_live_match_with_few_rounds_stays_visible(): void
    {
        // ended_at is only set once the match is over (gap timeout or a new
        // match starting) -- while it's still null the match is being played
        // right now, so it must not read as an abandoned/incomplete match.
        $server = $this->makeServer();
        $match = $this->makeMatch($server);
        $this->addRounds($match, 1);

        $this->assertNull($match->fresh()->ended_at);
        $this->assertSame(0, GameMatch::reachedConclusion()->count());
        $this->assertSame(1, GameMatch::visibleInListing()->count());
    }

    public function test_match_with_13_rounds_has_reached_conclusion(): void
    {
        $server = $this->makeServer();
        $match = $this->makeMatch($server);
        $this->addRounds($match, 13);
        $match->update(['ended_at' => now()]);

        $this->assertSame(1, GameMatch::reachedConclusion()->count());
        $this->assertSame(1, GameMatch::visibleInListing()->count());
    }

    public function test_match_with_match_end_event_has_reached_conclusion_even_with_few_rounds(): void
    {
        // Overtime tie broken early, or a short-round mode -- MatchEnd; is the
        // authoritative signal regardless of round count.
        $server = $this->makeServer();
        $match = $this->makeMatch($server);
        $this->addRounds($match, 3);
        $match->update(['ended_at' => now()]);

        MatchEvent::create([
            'server_id' => $server->id,
            'match_id' => $match->id,
            'event_type' => 'match_end',
            'occurred_at' => now(),
        ]);

        $this->assertSame(1, GameMatch::reachedConclusion()->count());
        $this->assertSame(1, GameMatch::visibleInListing()->count());
    }
}
