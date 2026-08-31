<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * Filtros por admin/tipo de accion/rango de fechas (2026-08-31, a pedido
     * del dueño) -- antes era una lista plana sin forma de acotar, y con
     * semanas de historial encontrar algo puntual era scrollear pagina por
     * pagina. "accion" es texto libre (like %x%) en vez de un <select> de
     * valores exactos -- las acciones ya usan puntos como namespace
     * (players.destroy, seasons.close, etc.), asi que "players." encuentra
     * cualquier accion sobre jugadores sin tener que listar cada variante.
     */
    public function index(Request $request)
    {
        $query = AdminAction::with('user');

        if ($request->filled('admin')) {
            $query->where('user_id', $request->integer('admin'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->string('action').'%');
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $actions = $query->latest()->paginate(50)->withQueryString();
        $admins = User::orderBy('username')->get(['id', 'username']);

        return view('admin.audit.index', compact('actions', 'admins'));
    }
}
