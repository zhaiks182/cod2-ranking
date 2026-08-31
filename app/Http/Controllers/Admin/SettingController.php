<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'demo_retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        Setting::current()->update([
            'demo_retention_days' => $validated['demo_retention_days'] ?? null,
        ]);

        return back()->with('status', 'Configuracion guardada.');
    }
}
