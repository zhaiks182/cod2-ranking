<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\MapCatalog;
use App\Support\MapImage;
use Illuminate\Http\Request;

class MapImageController extends Controller
{
    public function index()
    {
        $maps = collect(MapCatalog::all())->map(fn ($label, $code) => [
            'code' => $code,
            'label' => $label,
            'url' => MapImage::url($code),
        ])->values();

        return view('admin.maps.index', compact('maps'));
    }

    public function store(Request $request, string $code)
    {
        abort_unless(array_key_exists($code, MapCatalog::all()), 404);

        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        MapImage::store($code, $request->file('image'));

        return back()->with('status', 'Imagen actualizada.');
    }

    public function destroy(string $code)
    {
        MapImage::destroy($code);

        return back()->with('status', 'Imagen eliminada.');
    }
}
