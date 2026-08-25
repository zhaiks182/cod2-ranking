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

        $oldSeason = Season::current();

        $newSeason = \Illuminate\Support\Facades\DB::transaction(function () use ($oldSeason, $validated) {
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
}
