<?php

namespace App\Http\Middleware;

use App\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verhindert, dass eine bei Bootsschule A angemeldete Session Lerninhalte
 * von Bootsschule B sieht, nur weil die zentrale Dev-Domain den Tenant per
 * Query-Parameter wechselt. Produktiv trennt bereits die subdomain-gebundene
 * Session dies; dies ist die zusätzliche serverseitige Prüfung.
 */
class EnsureTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();

        if (! $user || ! $tenant || ! $user->roleForTenant($tenant)) {
            // Gesperrte oder beendete Mitgliedschaft: zur Seite „Meine Daten“
            // (Export und Kontolöschung bleiben möglich).
            $formerMember = $user && $tenant && TenantUser::on('pgsql_admin')->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
            if ($formerMember && $request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->route('profile.data-rights')
                    ->with('status', 'Dein Zugang zu dieser Bootsschule ist gesperrt. Deine Daten kannst du hier herunterladen oder dein Konto löschen.');
            }
            abort(403, 'Kein Zugang zu dieser Bootsschule.');
        }

        return $next($request);
    }
}
