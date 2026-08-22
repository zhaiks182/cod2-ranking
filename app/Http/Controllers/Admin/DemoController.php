<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Demo;
use App\Models\GameMatch;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

class DemoController extends Controller
{
    public function index()
    {
        $matches = GameMatch::whereHas('demos')
            ->withCount('demos')
            ->withSum('demos', 'size_bytes')
            ->orderByDesc('started_at')
            ->paginate(20);

        $setting = Setting::current();

        // Total real de TODOS los demos, no solo los de la pagina actual --
        // la tabla esta paginada de a 20 partidas, sumar demos_sum_size_bytes
        // visible daria solo el total de esta pagina.
        $totalDemos = Demo::count();
        $totalBytes = (int) Demo::sum('size_bytes');

        return view('admin.demos.index', compact('matches', 'setting', 'totalDemos', 'totalBytes'));
    }

    public function show(GameMatch $match)
    {
        $demos = $match->demos()->with('player')->orderBy('created_at')->get();

        return view('admin.demos.show', compact('match', 'demos'));
    }

    public function destroy(Demo $demo)
    {
        Storage::disk('local')->delete($demo->file_path);

        $label = $demo->demo_name;
        $matchId = $demo->match_id;
        $demo->delete();

        AdminAction::record('demos.destroy', "Elimino el demo ({$label})");

        return $matchId
            ? redirect()->route('admin.demos.show', $matchId)->with('status', "Demo eliminado ({$label}).")
            : redirect()->route('admin.demos.index')->with('status', "Demo eliminado ({$label}).");
    }

    /** Borra de una todos los demos de una partida (mapa+fecha), desde la fila del listado. */
    public function destroyByMatch(GameMatch $match)
    {
        $demos = $match->demos;
        $count = $demos->count();
        $label = \App\Support\MapCatalog::mapLabel($match->map).' — '.$match->started_at->format('d/m/Y H:i');

        foreach ($demos as $demo) {
            Storage::disk('local')->delete($demo->file_path);
            $demo->delete();
        }

        AdminAction::record('demos.destroy-match', "Elimino {$count} demo(s) de {$label}");

        return redirect()->route('admin.demos.index')->with('status', "Se eliminaron {$count} demo(s) de {$label}.");
    }
}
