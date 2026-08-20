<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Ban;
use App\Models\Server;
use App\Services\Cod2RconClient;
use Illuminate\Support\Facades\Auth;

class BanController extends Controller
{
    public function index()
    {
        $bans = Ban::with(['player', 'bannedBy', 'unbannedBy'])->latest()->paginate(50);

        return view('admin.bans.index', compact('bans'));
    }

    /**
     * unbanUser busca en ban.txt por nombre exacto (no por guid) -- ver
     * CoD2MP_s.c decompilado, "unbanned %i user(s) named %s". Por eso guardamos
     * player_name en el momento del ban: es lo unico que el comando nativo puede
     * usar para encontrar la entrada a borrar, incluso si el jugador cambio de
     * nombre despues.
     */
    public function destroy(Ban $ban)
    {
        $server = Server::first();

        if ($server) {
            Cod2RconClient::forServer($server)->command('unbanUser "'.str_replace('"', '', $ban->player_name).'"');
        }

        $ban->update([
            'unbanned_at' => now(),
            'unbanned_by' => Auth::id(),
        ]);

        AdminAction::record('bans.unban', "Desbaneo a {$ban->player_name} (guid {$ban->guid})");

        return back()->with('status', "Se desbaneó a {$ban->player_name}.");
    }
}
