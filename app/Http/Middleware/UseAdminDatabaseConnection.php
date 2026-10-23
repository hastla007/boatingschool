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
        // Bug-Review M6 (Folgefehler): ohne dieses Zurücksetzen bliebe
        // database.default dauerhaft auf der RLS-umgehenden Owner-
        // Verbindung stehen, sobald der PHP-Prozess, der einen
        // Superadmin-Request bedient hat, für einen WEITEREN Request
        // wiederverwendet wird (z. B. unter Laravel Octane/Swoole/
        // RoadRunner, die die Anwendung über mehrere Requests hinweg im
        // selben Worker am Leben halten) -- jeder folgende Request in
        // diesem Worker, auch ein ganz normaler Lerner-Request, würde dann
        // RLS vollständig umgehen, bis der Worker neu startet. Dasselbe
        // try/finally-Muster wie bei AccountErasureService::onAdminConnection()
        // und InteractsWithTenants::onAdmin().
        $original = config('database.default');
        config(['database.default' => 'pgsql_admin']);

        try {
            return $next($request);
        } finally {
            config(['database.default' => $original]);
        }
    }
}
