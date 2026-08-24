<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Server;
use App\Models\AdminAction;
use App\Support\StatsRecalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        // demos.match_id usa nullOnDelete() (no cascadeOnDelete() como rounds/kills) --
        // sin esto, el registro y el archivo del demo sobreviven a la partida como
        // huerfanos, invisibles desde /adm_cod2/demos (esa pantalla agrupa por
        // partida via GameMatch::whereHas('demos')) y sin forma de encontrarlos para
        // borrarlos despues. Borrar la partida borra sus demos de una.
        $demoCount = $match->demos->count();
        foreach ($match->demos as $demo) {
            Storage::disk('local')->delete($demo->file_path);
            $demo->delete();
        }

        $match->delete();

        $demoNote = $demoCount > 0 ? " y {$demoCount} demo(s)" : '';
        AdminAction::record('matches.destroy', "Elimino la partida ({$label}){$demoNote}");

        StatsRecalculator::recalculateAll();

        return back()->with('status', "Partida eliminada ({$label}){$demoNote} y estadísticas recalculadas.");
    }
}
