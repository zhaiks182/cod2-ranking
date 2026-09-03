<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Clan;
use Illuminate\Support\Facades\Storage;

class ClanController extends Controller
{
    public function index()
    {
        $clans = Clan::withCount('members')->orderByDesc('members_count')->get();

        return view('admin.clans.index', compact('clans'));
    }

    /** Disolucion forzada -- para intervenir ante abuso sin depender de que el fundador coopere. */
    public function destroy(Clan $clan)
    {
        $name = $clan->name;
        $tag = $clan->tag;

        if ($clan->logo_path) {
            Storage::disk('public')->delete($clan->logo_path);
        }
        $clan->delete();

        AdminAction::record('clans.destroy', "Disolvio el clan \"{$name}\" [{$tag}]");

        return back()->with('status', "Clan \"{$name}\" disuelto.");
    }
}
