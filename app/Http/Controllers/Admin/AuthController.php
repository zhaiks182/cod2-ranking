<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TurnstileVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('admin.servers.index');
        }

        return view('admin.login', ['turnstileSiteKey' => config('services.turnstile.site_key')]);
    }

    public function login(Request $request)
    {
        // Antes de validar credenciales -- el login de admin es el blanco mas
        // valioso de fuerza bruta de todo el sitio (sin login previo, sin rate
        // limit propio hasta ahora, ver revision final del task de throttle de
        // /servidores/crear del 2026-08-24, que marco este mismo gap). Mismo
        // helper que ya usa el form de servidores temporales -- si Turnstile no
        // esta configurado, se salta la verificacion (no rompe el login en dev).
        if (! TurnstileVerifier::passes($request)) {
            return back()->withErrors(['username' => 'No se pudo verificar que sos una persona. Probá de nuevo.'])->onlyInput('username');
        }

        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['username' => 'Credenciales incorrectas.'])->onlyInput('username');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.servers.index'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
