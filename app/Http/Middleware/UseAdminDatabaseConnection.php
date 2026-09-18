<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Superadmin-Bereich arbeitet bewusst mandantenübergreifend (alle
 * Bootsschulen, alle Nutzer). Die normale App-DB-Rolle wird durch RLS-
 * Policies strikt auf den aktuell gesetzten Tenant beschränkt (siehe
 * TenantContext), daher läuft dieser gesamte Bereich stattdessen über die
 * Owner-Verbindung "pgsql_admin", die RLS umgeht -- exakt das Muster, das
 * Migrationen, Seeder und Test-Fixtures (InteractsWithTenants::onAdmin())
 * bereits für mandantenübergreifende Operationen nutzen.
 */
class UseAdminDatabaseConnection
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['database.default' => 'pgsql_admin']);

        return $next($request);
    }
}
