<?php

namespace App\Http\Middleware;

use App\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Für Datenexport und Kontolöschung (Art. 15/17/20 DSGVO): jede
 * Mitgliedschaft in dieser Bootsschule reicht, auch eine gesperrte oder
 * beendete -- gerade dann muss die Person an ihre Daten kommen.
 */
class EnsureAnyTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();

        $isMember = $user && $tenant && TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
        abort_unless($isMember, 403, 'Kein Zugang zu dieser Bootsschule.');

        return $next($request);
    }
}
