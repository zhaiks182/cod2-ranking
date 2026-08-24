<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\LogParserState;
use App\Models\Round;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug real en vivo (2026-08-24): ended_at NO es una señal estable de "esta
 * partida ya terminó" -- openRound() la marca despues de CADA ronda (justo
 * cuando dispara RoundEnd;/ShutdownGame:) y la vuelve a poner en null apenas
 * arranca la ronda siguiente de la misma partida (ver el branch "continuing"
 * de openRound()). Con bots manteniendo rondas cada ~1 minuto, ended_at quedó
 * marcado justo en ese hueco entre rondas cuando corrió
 * cod2:recalculate-stats, y le borró los kills a un jugador de una partida
 * que seguía jugándose en vivo. log_parser_state.current_match_id es la
 * señal estable: mientras una partida siga siendo la actual del parser, no
 * puede considerarse abandonada sin importar lo que diga ended_at en ese
 * instante.
 */
class GameMatchStillCurrentTest extends TestCase
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

    public function test_match_with_transient_ended_at_but_still_current_is_not_abandoned(): void
    {
        $server = $this->makeServer();

        $match = GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Round::create([
            'server_id' => $server->id,
            'match_id' => $match->id,
            'map' => $match->map,
            'gametype' => $match->gametype,
            'started_at' => now(),
        ]);

        // The parser is still tracking this match as current for its server --
        // it's between rounds right now, not abandoned.
        LogParserState::create([
            'server_id' => $server->id,
            'log_path' => $server->log_path,
            'byte_offset' => 0,
            'current_match_id' => $match->id,
        ]);

        $this->assertSame(0, GameMatch::abandonedWithoutConclusion()->where('id', $match->id)->count());
        $this->assertSame(1, GameMatch::visibleInListing()->where('id', $match->id)->count());
    }

    public function test_match_no_longer_current_and_ended_without_conclusion_is_abandoned(): void
    {
        $server = $this->makeServer();

        $oldMatch = GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Round::create([
            'server_id' => $server->id,
            'match_id' => $oldMatch->id,
            'map' => $oldMatch->map,
            'gametype' => $oldMatch->gametype,
            'started_at' => now(),
        ]);

        $newMatch = GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_carentan_fix',
            'gametype' => 'sd',
            'started_at' => now(),
        ]);

        // The parser has moved on to a different match -- oldMatch has been
        // superseded, so it's fair to judge it as abandoned now.
        LogParserState::create([
            'server_id' => $server->id,
            'log_path' => $server->log_path,
            'byte_offset' => 0,
            'current_match_id' => $newMatch->id,
        ]);

        $this->assertSame(1, GameMatch::abandonedWithoutConclusion()->where('id', $oldMatch->id)->count());
        $this->assertSame(0, GameMatch::visibleInListing()->where('id', $oldMatch->id)->count());
    }
}
