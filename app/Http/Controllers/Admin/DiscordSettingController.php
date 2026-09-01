<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\DiscordWidgetService;
use Illuminate\Http\Request;

class DiscordSettingController extends Controller
{
    public function edit()
    {
        $setting = Setting::current();

        return view('admin.discord.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'discord_guild_id' => ['nullable', 'string', 'max:32'],
            'discord_invite_url' => ['nullable', 'url', 'max:255'],
            'discord_description' => ['nullable', 'string', 'max:500'],
            'discord_benefits' => ['nullable', 'string', 'max:2000'],
            'discord_match_webhook_url' => ['nullable', 'url', 'max:500', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
            'discord_teams_webhook_url' => ['nullable', 'url', 'max:500', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
        ]);

        Setting::current()->update($validated);

        // El widget de la home cachea 60s (ver DiscordWidgetService) -- si el
        // guild cambio aca, no tiene sentido dejar la respuesta vieja pegada
        // hasta que expire sola.
        DiscordWidgetService::forgetCache();

        return back()->with('status', 'Configuración de Discord guardada.');
    }
}
