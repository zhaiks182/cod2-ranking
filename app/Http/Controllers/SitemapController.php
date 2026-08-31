<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml (2026-08-31, a pedido del dueño) -- paginas publicas estaticas
 * mas cada partida/jugador real (dataset chico, ~70 partidas/~40 jugadores
 * hoy, asi que un solo archivo sin paginacion alcanza de sobra). Cacheado 1
 * hora -- no hace falta que sea instantaneo, los crawlers no lo piden tan
 * seguido, y esto evita que cada hit de un bot dispare las mismas queries.
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addHour(), function () {
            $staticRoutes = [
                ['route' => 'dashboard', 'priority' => '1.0', 'changefreq' => 'hourly'],
                ['route' => 'leaderboard', 'priority' => '0.9', 'changefreq' => 'hourly'],
                ['route' => 'rango', 'priority' => '0.6', 'changefreq' => 'daily'],
                ['route' => 'team-balance', 'priority' => '0.4', 'changefreq' => 'weekly'],
                ['route' => 'matches.index', 'priority' => '0.9', 'changefreq' => 'hourly'],
                ['route' => 'demos.index', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.grenades', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.headshots', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.friendly-fire', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.suicides', 'priority' => '0.4', 'changefreq' => 'daily'],
                ['route' => 'specialties.efficiency', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.maps-won', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.weapons', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.rivalries', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.map-kings', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.playtime', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.streaks', 'priority' => '0.4', 'changefreq' => 'daily'],
                ['route' => 'specialties.recent-activity', 'priority' => '0.6', 'changefreq' => 'hourly'],
                ['route' => 'specialties.countries', 'priority' => '0.4', 'changefreq' => 'weekly'],
                ['route' => 'specialties.clutches', 'priority' => '0.4', 'changefreq' => 'daily'],
                ['route' => 'specialties.streaks-kills', 'priority' => '0.4', 'changefreq' => 'daily'],
                ['route' => 'specialties.chattiest', 'priority' => '0.3', 'changefreq' => 'weekly'],
                ['route' => 'specialties.peak-times', 'priority' => '0.3', 'changefreq' => 'weekly'],
                ['route' => 'specialties.timeouts', 'priority' => '0.3', 'changefreq' => 'weekly'],
                ['route' => 'specialties.bash', 'priority' => '0.3', 'changefreq' => 'weekly'],
                ['route' => 'specialties.win-rate', 'priority' => '0.5', 'changefreq' => 'daily'],
                ['route' => 'specialties.bombs', 'priority' => '0.4', 'changefreq' => 'daily'],
                ['route' => 'specialties.damage', 'priority' => '0.4', 'changefreq' => 'daily'],
                ['route' => 'specialties.disconnects', 'priority' => '0.3', 'changefreq' => 'weekly'],
                ['route' => 'specialties.grenade-deaths', 'priority' => '0.3', 'changefreq' => 'daily'],
                ['route' => 'faq', 'priority' => '0.3', 'changefreq' => 'monthly'],
                ['route' => 'downloads', 'priority' => '0.4', 'changefreq' => 'monthly'],
            ];

            $matches = GameMatch::visibleInListing()
                ->where('gametype', 'sd')
                ->where('is_backfilled', false)
                ->orderByDesc('id')
                ->get(['id', 'updated_at']);

            $players = Player::where(function ($q) {
                $q->where('kills_total', '>', 0)->orWhere('deaths_total', '>', 0);
            })->get(['guid', 'updated_at']);

            return view('sitemap', compact('staticRoutes', 'matches', 'players'))->render();
        });

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
