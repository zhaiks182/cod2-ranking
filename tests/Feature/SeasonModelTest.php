<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_temporada_1_as_the_active_season(): void
    {
        $season = Season::current();

        $this->assertSame('Temporada 1', $season->name);
        $this->assertNull($season->ended_at);
    }

    public function test_current_returns_the_season_with_null_ended_at(): void
    {
        $old = Season::current();
        $old->update(['ended_at' => now()]);

        $new = Season::create(['name' => 'Temporada 2', 'started_at' => now(), 'ended_at' => null]);

        $this->assertTrue(Season::current()->is($new));
    }

    public function test_game_match_belongs_to_a_season(): void
    {
        $season = Season::current();

        $match = GameMatch::create([
            'server_id' => 1,
            'map' => 'mp_toujane_fix',
            'gametype' => 'sd',
            'started_at' => now(),
            'season_id' => $season->id,
        ]);

        $this->assertTrue($match->season->is($season));
        $this->assertTrue($season->matches->contains($match));
    }
}
