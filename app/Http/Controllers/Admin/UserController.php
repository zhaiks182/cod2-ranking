<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios y roles del panel admin (2026-08-31, a pedido del dueño) --
 * gatead por EnsureIsSuperAdmin (middleware 'super-admin'), nunca por
 * User::MODULES (ver esa constante para el porque).
 */
class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('username')->get();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $user = User::create([
            'name' => $validated['username'],
            'username' => $validated['username'],
            'email' => $validated['username'].'@adm.local',
            'password' => Hash::make($validated['password']),
            'is_super_admin' => $validated['is_super_admin'],
            'permissions' => $validated['is_super_admin'] ? [] : $validated['permissions'],
        ]);

        AdminAction::record('users.create', "Creó al usuario admin \"{$user->username}\"".($user->is_super_admin ? ' (super-admin)' : ' (módulos: '.implode(', ', $user->permissions ?: ['ninguno']).')'));

        return redirect()->route('admin.users.index')->with('status', 'Usuario creado.');
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $this->validated($request, $user);

        // Sin esto, el ultimo super-admin podria degradarse a si mismo (o ser
        // degradado por otro super-admin) y dejar el panel sin nadie que pueda
        // arreglar permisos -- mismo espiritu que la validacion ya existente
        // de "no borrar el ultimo puerto en uso" en SettingController.
        if ($user->is_super_admin && ! $validated['is_super_admin'] && User::where('is_super_admin', true)->count() <= 1) {
            return back()->withErrors(['is_super_admin' => 'No podés sacarle super-admin al único super-admin que queda.'])->withInput();
        }

        $user->update([
            'username' => $validated['username'],
            'is_super_admin' => $validated['is_super_admin'],
            'permissions' => $validated['is_super_admin'] ? [] : $validated['permissions'],
            'password' => filled($validated['password']) ? Hash::make($validated['password']) : $user->password,
        ]);

        AdminAction::record('users.update', "Editó al usuario admin \"{$user->username}\"".($user->is_super_admin ? ' (super-admin)' : ' (módulos: '.implode(', ', $user->permissions ?: ['ninguno']).')'));

        return redirect()->route('admin.users.index')->with('status', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'No podés borrar tu propia cuenta mientras estás conectado con ella.']);
        }

        if ($user->is_super_admin && User::where('is_super_admin', true)->count() <= 1) {
            return back()->withErrors(['user' => 'No podés borrar al único super-admin que queda.']);
        }

        $username = $user->username;
        $user->delete();

        AdminAction::record('users.destroy', "Borró al usuario admin \"{$username}\"");

        return back()->with('status', 'Usuario borrado.');
    }

    /** @return array{username: string, password: ?string, is_super_admin: bool, permissions: array<int, string>} */
    private function validated(Request $request, ?User $user = null): array
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64', 'alpha_dash', $user
                ? \Illuminate\Validation\Rule::unique('users', 'username')->ignore($user->id)
                : \Illuminate\Validation\Rule::unique('users', 'username')],
            'password' => [$user ? 'nullable' : 'required', 'min:8'],
            'is_super_admin' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(User::MODULES))],
        ]);

        $validated['is_super_admin'] = $request->boolean('is_super_admin');
        $validated['permissions'] = $validated['permissions'] ?? [];
        // 'nullable' en la regla de arriba solo evita el error de validacion si el
        // campo viene vacio -- si el campo NO viene en absoluto en el request (caso
        // normal al editar sin cambiar la contraseña), la clave ni siquiera existe
        // en $validated, asi que hay que asegurarla aca para poder leerla despues.
        $validated['password'] = $validated['password'] ?? null;

        return $validated;
    }
}
