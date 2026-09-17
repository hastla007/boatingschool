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
            abort(403, 'Für diese Bootsschule fehlt die erforderliche Rolle.');
        }

        return $next($request);
    }
}
