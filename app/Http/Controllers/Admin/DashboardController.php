<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Demo;
use App\Models\GameMatch;
use App\Models\HostedServer;
use App\Models\Player;
use App\Models\Season;
use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * Panel de inicio de /adm_cod2 (2026-08-31, a pedido del dueño) -- antes
 * "/adm_cod2" solo redirigia a la lista de servidores, sin ningun resumen al
 * entrar. Solo lectura (counts + ultimas acciones), nada que necesite su
 * propio modulo de permisos -- cualquier admin autenticado lo ve.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'players_total' => Player::count(),
            'matches_today' => GameMatch::whereDate('started_at', Carbon::today())->count(),
            'matches_total' => GameMatch::count(),
            'demos_total' => Demo::count(),
            'demos_size_human' => $this->formatBytes(Demo::sum('size_bytes')),
            'hosted_servers_active' => HostedServer::whereIn('status', ['starting', 'running'])->count(),
            'hosted_servers_max' => \App\Models\Setting::maxConcurrent(),
        ];

        $season = Season::current();
        $servers = Server::where('is_active', true)->orderBy('name')->get();
        $recentActions = AdminAction::with('user')->latest()->limit(8)->get();

        $disk = [
            'free' => @disk_free_space(base_path()),
            'total' => @disk_total_space(base_path()),
        ];
        $disk['used_percent'] = ($disk['free'] !== false && $disk['total'])
            ? round((1 - $disk['free'] / $disk['total']) * 100, 1)
            : null;
        $disk['free_human'] = $disk['free'] !== false ? $this->formatBytes((int) $disk['free']) : null;

        return view('admin.dashboard', compact('stats', 'season', 'servers', 'recentActions', 'disk'));
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1).' '.$units[$i];
    }
}
