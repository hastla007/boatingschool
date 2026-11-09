<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();

        if (! $user || ! $tenant) {
            abort(403);
        }

        $role = $user->roleForTenant($tenant);

        if (! $role || ! in_array($role, $roles, true)) {
            // Review-Runde 11: passende Meldung je nach Rolle.
            abort(403, match (true) {
                in_array('learner', $roles, true) => 'Dieser Bereich ist für Teilnehmer gedacht.',
                $role === 'instructor' => 'Dieser Bereich ist der Leitung der Bootsschule (Inhaber und Admins) vorbehalten.',
                default => 'Dieser Bereich ist nur für das Team der Bootsschule.',
            });
        }

        return $next($request);
    }
}
