<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;

class AuditController extends Controller
{
    public function index()
    {
        $actions = AdminAction::with('user')->latest()->paginate(50);

        return view('admin.audit.index', compact('actions'));
    }
}
