<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Server;
use App\Support\StatsRecalculator;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();

        $matches = GameMatch::where('server_id', $server?->id)
            ->withCount(['rounds', 'kills'])
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.matches.index', compact('servers', 'server', 'matches'));
    }

    public function destroy(GameMatch $match)
    {
        $label = \App\Support\MapCatalog::mapLabel($match->map).' — '.($match->started_at?->format('d/m/Y H:i') ?? 'sin fecha');

        $match->delete();

        StatsRecalculator::recalculateAll();

        return back()->with('status', "Partida eliminada ({$label}) y estadísticas recalculadas.");
    }
}
