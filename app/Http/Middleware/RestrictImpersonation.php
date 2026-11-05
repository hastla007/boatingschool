<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
use App\Support\CanonicalUrl;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support-Ansicht („Als Nutzer ansehen“) ist nur lesend: alle
 * Schreib-Anfragen außer „Beenden“ und Abmelden werden abgelehnt und
 * protokolliert. Nach Impersonation::MAX_MINUTES endet sie automatisch.
 */
class RestrictImpersonation
{
    private const ALLOWED_ROUTES = ['impersonate.stop', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $impersonation = Impersonation::current($request);
        if (! $impersonation) {
            return $next($request);
        }

        if (now()->timestamp - $impersonation['started_at'] > Impersonation::MAX_MINUTES * 60) {
            ImpersonationController::audit($impersonation['tenant_id'], $impersonation['impersonator_id'], 'superadmin.impersonate.expired', $request->user()?->id);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->away(CanonicalUrl::root().route('superadmin.dashboard', [], false));
        }

        if (! $request->isMethodSafe() && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            ImpersonationController::audit($impersonation['tenant_id'], $impersonation['impersonator_id'], 'superadmin.impersonate.blocked', $request->user()?->id, [
                'method' => $request->method(), 'route' => $request->route()?->getName(),
            ]);
            $message = 'In der Support-Ansicht sind Änderungen gesperrt.';

            return $request->expectsJson() ? response()->json(['message' => $message], 403) : back()->with('status', $message);
        }

        return $next($request);
    }
}
