<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bug-Review B7: Laravels "Angemeldet bleiben"-Mechanismus (SessionGuard::
 * userFromRecaller() -> EloquentUserProvider::retrieveByToken()) prüft
 * beim automatischen Wiederanmelden über das Remember-me-Cookie nur, ob
 * das Token zur gespeicherten Nutzer-ID passt -- nicht, ob das Konto
 * überhaupt noch status=active hat. Eine Sperrung rotiert zwar den
 * remember_token (siehe PasswordController, Superadmin\UserController),
 * das schützt aber nur, solange jede Sperr-Stelle das zuverlässig tut; die
 * Lücke selbst (fehlende Statusprüfung im Recall) bliebe offen. Diese
 * Middleware prüft deshalb bei JEDEM Request zusätzlich explizit, ob das
 * gerade aufgelöste Konto (egal ob per Session oder per Remember-me-Cookie
 * angemeldet) noch aktiv ist, und meldet es sonst sofort ab -- unabhängig
 * davon, über welchen Weg die Sperrung passierte.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Dieses Konto ist gesperrt. Bitte wende dich an deine Bootsschule.');
        }

        return $next($request);
    }
}
