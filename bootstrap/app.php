<?php

use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\EnsureTenantRole;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseAdminDatabaseConnection;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Bug-Review M4 (Folgefehler): ohne eine vertrauenswürdige
        // TrustProxies-Konfiguration liefert $request->ip() hinter JEDEM
        // Reverse-Proxy (auch einem rein lokalen, z. B. nginx vor php-fpm
        // in einem Docker-Setup) immer die Adresse des Proxys statt des
        // echten Clients -- der Localhost-Check in
        // ResolveTenant::resolveViaDevOverride() (Sicherheitsreview M4)
        // griff deshalb hinter einem solchen Proxy nie mehr, selbst wenn
        // der tatsächliche Client echtes Localhost war. TRUSTED_PROXIES
        // bleibt standardmäßig leer (= heutiges, sicheres Verhalten:
        // keinem Proxy vertrauen) und muss explizit auf die IP(s)/CIDR(s)
        // des EIGENEN, bekannten Proxys gesetzt werden -- bewusst NICHT
        // "*": sonst ließe sich X-Forwarded-For von jedem beliebigen
        // Client fälschen, was sowohl diesen M4-Check als auch die
        // IP-basierten throttle-Limits (routes/auth.php) aushebeln würde.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        $middleware->trustProxies(at: $trustedProxies !== [] ? $trustedProxies : null);

        $middleware->web(append: [
            SecurityHeaders::class,
            EnforceMaintenanceMode::class,
            EnsureAccountIsActive::class,
            ResolveTenant::class,
        ]);

        // Muss nach StartSession (Dev-Override liest die Session) aber vor
        // SubstituteBindings laufen: Route-Model-Binding für tenant-gebundene
        // Modelle (CourseDefinition, ExamSession, ...) braucht den bereits
        // aufgelösten Tenant-Kontext für RLS und den globalen Scope.
        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: ResolveTenant::class,
        );

        // EnsureSuperAdmin (der "superadmin"-Alias) muss nach Authenticate
        // laufen (braucht $request->user()) und VOR UseAdminDatabaseConnection
        // -- sonst würde admin-db die DB-Verbindung schon für nicht
        // angemeldete oder nicht berechtigte Requests auf /superadmin/*
        // umschalten, bevor der eigentliche Zugriffsschutz überhaupt greift
        // (Sicherheitsreview N10: admin-db stand vorher noch vor
        // Authenticate in der Default-Priorität).
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: EnsureSuperAdmin::class,
        );

        // Muss weiterhin vor SubstituteBindings laufen: Route-Model-Binding
        // für Superadmin-Routen (Tenant, Module, ContentQuestion, ...) muss
        // bereits auf der RLS-freien Owner-Verbindung hydriert werden --
        // sonst "frieren" die gebundenen Models auf der App-Verbindung ein
        // (Eloquent setzt die Connection beim Hydrieren fest), was zu
        // unsichtbaren RLS-gefilterten Relationen und Foreign-Key-Fehlern
        // bei Schreibzugriffen aus derselben Transaktion führt. Jetzt erst
        // NACH EnsureSuperAdmin, nicht mehr direkt nach ResolveTenant.
        $middleware->appendToPriorityList(
            after: EnsureSuperAdmin::class,
            append: UseAdminDatabaseConnection::class,
        );

        $middleware->alias([
            'tenant.role' => EnsureTenantRole::class,
            'tenant.member' => EnsureTenantMembership::class,
            'superadmin' => EnsureSuperAdmin::class,
            'admin-db' => UseAdminDatabaseConnection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
