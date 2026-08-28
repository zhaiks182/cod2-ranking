<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Player;
use App\Support\PlayerMerger;
use Illuminate\Http\Request;

class PlayerMergeController extends Controller
{
    /**
     * Busca por nombre actual O por cualquier alias historico -- necesario porque
     * el nombre visible de un jugador cambia todo el tiempo (ver "ALIAS USADOS" en
     * el perfil publico); buscar solo por last_name_plain no hubiera encontrado,
     * por ejemplo, las 3 filas de "MOKOS" (2026-08-28) por el nombre "MOKOS" ya
     * que la fila mas reciente se llama "MOKOS RELOAD".
     */
    public function index(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $results = collect();

        if ($query !== '') {
            $results = Player::where('last_name_plain', 'like', "%{$query}%")
                ->orWhereHas('aliases', fn ($q) => $q->where('name_plain', 'like', "%{$query}%"))
                ->with(['aliases' => fn ($q) => $q->orderByDesc('last_seen_at')])
                ->orderByDesc('last_seen_at')
                ->get();
        }

        return view('admin.players.merge', compact('query', 'results'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer', 'exists:players,id'],
            'source_ids' => ['required', 'array', 'min:2'],
            'source_ids.*' => ['integer', 'exists:players,id'],
        ]);

        $sourceIds = array_map('intval', $data['source_ids']);
        $targetId = (int) $data['target_id'];

        if (! in_array($targetId, $sourceIds, true)) {
            return back()->withErrors(['target_id' => 'El perfil destino tiene que ser uno de los seleccionados para fusionar.'])->withInput();
        }

        $players = Player::whereIn('id', $sourceIds)->get()->keyBy('id');
        $target = $players->get($targetId);
        $sources = $players->except($targetId);

        $sourceLabel = $sources->map(fn ($p) => "{$p->last_name_plain} (guid {$p->guid})")->implode(', ');

        PlayerMerger::merge($sources->pluck('id')->all(), $target->id);

        AdminAction::record(
            'players.merge',
            "Fusiono a {$sourceLabel} dentro de \"{$target->last_name_plain}\" (guid {$target->guid})"
        );

        return redirect()->route('players.show', $target->guid)
            ->with('status', 'Se fusionaron '.$sources->count()." perfil(es) dentro de \"{$target->last_name_plain}\".");
    }
}
