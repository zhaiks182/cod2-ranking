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

        return view('admin.demos.index', compact('matches', 'setting'));
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
}
