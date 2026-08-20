<?php

namespace App\Http\Controllers;

use App\Models\Demo;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DemosController extends Controller
{
    public function index()
    {
        $matches = GameMatch::whereHas('demos')
            ->withCount('demos')
            ->orderByDesc('started_at')
            ->paginate(20);

        $grouped = $matches->getCollection()->groupBy(fn (GameMatch $m) => $m->started_at->toDateString());

        return view('demos.index', compact('matches', 'grouped'));
    }

    public function show(GameMatch $match)
    {
        $demos = $match->demos()->with('player')->orderBy('created_at')->get();

        return view('demos.show', compact('match', 'demos'));
    }

    public function download(Demo $demo): StreamedResponse
    {
        return Storage::disk('local')->download($demo->file_path, $demo->demo_name.'.dm_1');
    }
}
