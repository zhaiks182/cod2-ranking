<?php

namespace Tests\Feature\Specialties;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for the whole bug class behind the 2026-08-26 final review
 * (commit cb08eaa: killStreaks() 500 on /rachas-de-bajas; commit 17a3a34:
 * /bombas, /dano, /desconexiones 500 because the shared `specialties/ranking.blade.php`
 * included the season selector unconditionally even though those three controller
 * methods never pass $seasonId/$seasons). No test in the suite would have caught
 * either bug before this file existed.
 *
 * Deliberately runs against an EMPTY database (no Server, no fixtures) — every one
 * of these 25 routes must render 200 with nothing to show, same as a fresh install
 * or a server with `is_active=false`/no active server at all. `resolveServer()`
 * already handles `$server === null` gracefully for all of them (falls back to an
 * empty $rows collection instead of querying), so this is exactly the invariant
 * this test protects: any current or future specialty method that forgets to pass
 * $seasonId/$seasons to a view sharing `specialties/ranking.blade.php` must fail
 * here instead of 500ing in production.
 */
class AllRoutesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'rango',
        'specialties.grenades',
        'specialties.headshots',
        'specialties.friendly-fire',
        'specialties.suicides',
        'specialties.efficiency',
        'specialties.maps-won',
        'specialties.weapons',
        'specialties.rivalries',
        'specialties.map-kings',
        'specialties.playtime',
        'specialties.streaks',
        'specialties.recent-activity',
        'specialties.countries',
        'specialties.clutches',
        'specialties.streaks-kills',
        'specialties.chattiest',
        'specialties.peak-times',
        'specialties.timeouts',
        'specialties.bash',
        'specialties.win-rate',
        'specialties.bombs',
        'specialties.damage',
        'specialties.disconnects',
        'specialties.grenade-deaths',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // specialties.peak-times (peakTimes()) uses hour()/dayofweek(), native
        // MySQL/MariaDB functions (the real production engine) that SQLite (the
        // test engine, see phpunit.xml) doesn't ship — same minimal user-defined
        // equivalents already registered by GroupCSeasonTest::setUp() for the same
        // reason, needed here because a default active Server is seeded by
        // database/migrations/2026_08_10_090005_seed_default_server_and_backfill.php
        // on every RefreshDatabase run, so resolveServer() never returns null and
        // this route's real query always executes.
        DB::connection()->getPdo()->sqliteCreateFunction('hour', fn ($datetime) => (int) date('G', strtotime($datetime)));
        DB::connection()->getPdo()->sqliteCreateFunction('dayofweek', fn ($datetime) => ((int) date('w', strtotime($datetime))) + 1);
    }

    public function test_all_specialty_routes_return_200_with_no_query_string(): void
    {
        foreach (self::ROUTES as $name) {
            $response = $this->get(route($name));
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Route [{$name}] with no query string returned {$response->getStatusCode()} instead of 200."
            );
        }
    }

    public function test_all_specialty_routes_return_200_with_season_all(): void
    {
        foreach (self::ROUTES as $name) {
            $response = $this->get(route($name, ['season' => 'all']));
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Route [{$name}] with ?season=all returned {$response->getStatusCode()} instead of 200."
            );
        }
    }
}
