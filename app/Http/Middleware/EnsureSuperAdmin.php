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

        return $next($request);
    }
}
