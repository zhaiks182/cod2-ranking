<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

/**
 * Bandeja de notificaciones dentro del sitio (2026-09-02, modulo de
 * galeria) -- usa el sistema nativo de Laravel (canal `database`, trait
 * Notifiable ya presente en SiteUser). Ver docs/superpowers/specs/
 * 2026-09-02-galeria-multimedia-design.md.
 */
class NotificationController extends Controller
{
    public function index()
    {
        $siteUser = Auth::guard('site')->user();
        $notifications = $siteUser->notifications()->paginate(20);

        $siteUser->unreadNotifications->markAsRead();

        return view('notifications.index', compact('notifications'));
    }
}
