<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA-Pflicht fürs Team (tenant_branding.require_team_two_factor): Inhaber,
 * Admins und Ausbilder ohne eingerichtete Zwei-Faktor-Anmeldung werden zur
 * Einrichtung geleitet. Lernende sind nicht betroffen, ebenso die
 * Support-Ansicht des Superadmins.
 */
class EnforceTeamTwoFactor
{
    private const ALLOWED_ROUTES = ['two-factor.show', 'two-factor.enable', 'two-factor.confirm', 'logout', 'impersonate.stop', 'legal.show'];

    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->tenantContext->has() || Impersonation::active($request) || $user->hasTwoFactor()) {
            return $next($request);
        }

        $tenant = $this->tenantContext->tenant();
        if (! $tenant->branding?->require_team_two_factor || in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }
        if (in_array($user->roleForTenant($tenant), ['learner', null], true)) {
            return $next($request);
        }

        return redirect()->route('two-factor.show')
            ->with('status', 'Deine Bootsschule verlangt für das Team die Zwei-Faktor-Anmeldung. Bitte richte sie jetzt ein.');
    }
}
