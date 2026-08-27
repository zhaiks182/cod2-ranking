<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Season;
use Illuminate\Http\Request;

class SeasonController extends Controller
{
    public function index()
    {
        $seasons = Season::withCount('matches')->orderByDesc('started_at')->get();

        return view('admin.seasons.index', compact('seasons'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $oldSeason = null;

        $newSeason = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, &$oldSeason) {
            $oldSeason = Season::whereNull('ended_at')->lockForUpdate()->firstOrFail();
            $oldSeason->update(['ended_at' => now()]);

            return Season::create([
                'name' => $validated['name'],
                'started_at' => now(),
                'ended_at' => null,
            ]);
        });

        AdminAction::record(
            'seasons.close',
            "Cerró \"{$oldSeason->name}\" e inició \"{$newSeason->name}\""
        );

        return redirect()->route('admin.seasons.index')->with('status', "Se inició \"{$newSeason->name}\".");
    }

    /**
     * Reactiva una temporada cerrada: cierra la que esté activa ahora mismo y
     * reabre la elegida (started_at original intacto, solo se toca
     * ended_at). No hay restricción de "solo la más reciente" — cualquier
     * temporada cerrada se puede volver a activar, a pedido del dueño.
     */
    public function reactivate(Season $season)
    {
        if ($season->ended_at === null) {
            return back()->withErrors(['season' => 'Esa temporada ya está activa.']);
        }

        $oldActive = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($season, &$oldActive) {
            $oldActive = Season::whereNull('ended_at')->lockForUpdate()->firstOrFail();
            $oldActive->update(['ended_at' => now()]);
            $season->update(['ended_at' => null]);
        });

        AdminAction::record(
            'seasons.reactivate',
            "Cerró \"{$oldActive->name}\" y reactivó \"{$season->name}\""
        );

        return redirect()->route('admin.seasons.index')->with('status', "\"{$season->name}\" está activa de nuevo.");
    }
}
