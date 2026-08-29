<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    public function switch(string $locale): RedirectResponse
    {
        if (! in_array($locale, SetLocale::SUPPORTED, true)) {
            abort(404);
        }

        // 1 año -- misma duracion que la cookie de "mi servidor temporal"
        // (HostedServer::COOKIE_NAME), es una preferencia de largo plazo, no de
        // sesion.
        Cookie::queue('locale', $locale, 60 * 24 * 365);

        return redirect()->back();
    }
}
