<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function index()
    {
        $servers = Server::orderBy('name')->get();

        return view('admin.servers.index', compact('servers'));
    }

    public function create()
    {
        return view('admin.servers.form', ['server' => new Server]);
    }

    public function store(Request $request)
    {
        Server::create($this->validated($request));

        return redirect()->route('admin.servers.index')->with('status', 'Servidor creado.');
    }

    public function edit(Server $server)
    {
        return view('admin.servers.form', compact('server'));
    }

    public function update(Request $request, Server $server)
    {
        $data = $this->validated($request, $server);

        if ($data['rcon_password'] === null) {
            unset($data['rcon_password']);
        }

        $server->update($data);

        return redirect()->route('admin.servers.index')->with('status', 'Servidor actualizado.');
    }

    public function destroy(Server $server)
    {
        $server->delete();

        return back()->with('status', 'Servidor eliminado.');
    }

    private function validated(Request $request, ?Server $server = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('servers', 'slug')->ignore($server?->id)],
            'log_path' => ['required', 'string', 'max:500'],
            'rcon_host' => ['required', 'string', 'max:255'],
            'rcon_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'rcon_password' => [$server ? 'nullable' : 'required', 'string'],
            'connect_ip' => ['required', 'string', 'max:255'],
            'connect_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'join_password' => ['nullable', 'string', 'max:255'],
            'max_clients' => ['required', 'integer', 'min:1', 'max:128'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['rcon_password'] = $data['rcon_password'] ?? null;

        return $data;
    }
}
