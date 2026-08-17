<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\PlayerServerStat;
use App\Models\Server;
use App\Services\Cod2RconClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $this->resolveServer($request, $servers);

        [$status, $latestMatch, $topPlayers] = $this->loadServerData($server);

        return view('dashboard', compact('servers', 'server', 'status', 'latestMatch', 'topPlayers'));
    }

    /**
     * Returns just the live-status widget markup, for the front-end poll (see
     * resources/views/partials/live-status.blade.php's refresh script) that keeps it
     * fresh without a full page reload.
     */
    public function liveStatusWidget(Request $request)
    {
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $server = $this->resolveServer($request, $servers);

        [$status, $latestMatch] = $this->loadServerData($server);

        return view('partials.live-status', compact('server', 'status', 'latestMatch'));
    }

    private function resolveServer(Request $request, $servers): ?Server
    {
        if ($slug = $request->query('server')) {
            return $servers->firstWhere('slug', $slug) ?? $servers->first();
        }

        return $servers->first();
    }

    private function loadServerData(?Server $server): array
    {
        if (! $server) {
            return [null, null, collect()];
        }

        // Cache::remember() would cache a null just as happily as a real result — a
        // single transient RCON blip (UDP has no delivery guarantee) would then show
        // "No se pudo conectar" for the full 15s instead of self-healing on the very
        // next poll. Only cache genuine successes; a failure is retried immediately.
        $cacheKey = "cod2:rcon:status:{$server->id}";
        $status = Cache::get($cacheKey);
        if ($status === null) {
            $status = Cod2RconClient::forServer($server)->status();
            if ($status !== null) {
                Cache::put($cacheKey, $status, 15);
            }
        }

        $latestMatch = GameMatch::where('server_id', $server->id)->latest('id')->first();

        $topPlayers = PlayerServerStat::where('server_id', $server->id)
            ->where(fn ($q) => $q->where('kills', '>', 0)->orWhere('deaths', '>', 0))
            ->with('player')
            ->whereHas('player')
            ->orderByDesc('kills')
            ->limit(10)
            ->get();

        return [$status, $latestMatch, $topPlayers];
    }
}
