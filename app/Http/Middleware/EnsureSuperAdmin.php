<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plattformweite Rolle (app_user.is_superadmin), unabhängig von jeder
 * einzelnen Bootsschule -- im Unterschied zu tenant.role, das immer den
 * aktuell aufgelösten Mandanten voraussetzt.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_superadmin, 403, 'Nur für Plattform-Administratoren.');

        // Zugriff auf alle Schulen (Owner-Verbindung, Impersonation): nur mit
        // Zwei-Faktor-Anmeldung. Die Einrichtung liegt außerhalb dieses Bereichs.
        if (config('auth.superadmin_two_factor') && ! $user->hasTwoFactor()) {
            return redirect()->route('two-factor.show')
                ->with('status', 'Für den Superadmin-Bereich ist die Zwei-Faktor-Anmeldung Pflicht. Bitte richte sie jetzt ein.');
        }

        return $next($request);
    }
}
