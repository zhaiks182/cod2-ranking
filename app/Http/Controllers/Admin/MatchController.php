<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Server;
use App\Models\AdminAction;
use App\Support\StatsRecalculator;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::orderBy('name')->get();
        $server = $servers->firstWhere('slug', $request->query('server')) ?? $servers->first();
        $showIncomplete = $request->boolean('incompletas');

        $matches = GameMatch::where('server_id', $server?->id)
            ->when(! $showIncomplete, fn ($q) => $q->visibleInListing())
            ->withCount(['rounds', 'kills'])
            ->with(['events' => fn ($q) => $q->where('event_type', 'match_end')])
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString();

        $toggleQuery = array_filter(array_merge($request->query(), ['incompletas' => $showIncomplete ? null : 1]), fn ($v) => $v !== null);

        return view('admin.matches.index', compact('servers', 'server', 'matches', 'showIncomplete', 'toggleQuery'));
    }

    public function destroy(GameMatch $match)
    {
        $label = \App\Support\MapCatalog::mapLabel($match->map).' — '.($match->started_at?->format('d/m/Y H:i') ?? 'sin fecha');

        $match->delete();

        AdminAction::record('matches.destroy', "Elimino la partida ({$label})");

        StatsRecalculator::recalculateAll();

        return back()->with('status', "Partida eliminada ({$label}) y estadísticas recalculadas.");
    }
}
