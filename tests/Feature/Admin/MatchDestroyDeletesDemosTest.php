<?php

namespace Tests\Feature\Admin;

use App\Models\Demo;
use App\Models\GameMatch;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * demos.match_id usa nullOnDelete() (no cascadeOnDelete() como rounds/kills)
 * -- borrar una partida sin tocar sus demos los dejaba huerfanos: el
 * registro y el archivo sobrevivían, pero invisibles desde /adm_cod2/demos
 * (esa pantalla agrupa por partida) y sin forma de encontrarlos para
 * borrarlos después. Confirmado con el dueño: borrar la partida debe borrar
 * sus demos también.
 */
class MatchDestroyDeletesDemosTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroying_a_match_deletes_its_demos_and_files(): void
    {
        Storage::fake('local');

        $server = Server::create([
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

        $match = GameMatch::create([
            'server_id' => $server->id,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Storage::disk('local')->put('demos/test-demo.dm_1', 'fake demo contents');

        $demo = Demo::create([
            'match_id' => $match->id,
            'hwid' => 'ABC123',
            'demo_name' => 'test-demo.dm_1',
            'file_path' => 'demos/test-demo.dm_1',
            'size_bytes' => 19,
        ]);

        $admin = User::factory()->create();
        $this->actingAs($admin)
            ->delete(route('admin.matches.destroy', $match))
            ->assertRedirect();

        $this->assertDatabaseMissing('matches', ['id' => $match->id]);
        $this->assertDatabaseMissing('demos', ['id' => $demo->id]);
        Storage::disk('local')->assertMissing('demos/test-demo.dm_1');
    }
}
