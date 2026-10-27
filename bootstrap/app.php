<?php

use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\EnsureTenantRole;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseAdminDatabaseConnection;
use App\Support\TrustedHosts;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
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
        //
        // Bug-Review (Hoch, eigener Fund): config('app.trusted_proxies')
        // statt eines direkten env()-Aufrufs für TRUSTED_PROXIES -- env()
        // außerhalb einer config/*.php-Datei liefert nach `php artisan
        // config:cache`
        // (Teil des produktiv üblichen `optimize`) immer nur den Default,
        // weil Laravel die .env dann gar nicht mehr lädt. Siehe
        // config/app.php für die ausführliche Begründung.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('app.trusted_proxies', ''))
        )));

        $middleware->trustProxies(at: $trustedProxies !== [] ? $trustedProxies : null);

        // Bug-Review R3-2/R4-A: ohne trustHosts() vertraut Laravel jedem
        // Host-Header blind -- route()/url() erzeugen absolute URLs (z. B.
        // der Link in der Passwort-Reset-Mail) damit mit dem Host, den der
        // CLIENT schickt; ein gefälschter Host-Header hätte diesen Link auf
        // eine beliebige, vom Angreifer kontrollierte Domain zeigen lassen
        // können (Host-Header-Poisoning). Zusätzlich löst TrustProxies bei
        // einem Host-Header, der auf ".on-forge.com"/".on-vapor.com" endet,
        // AUTOMATISCH "vertraue jedem Proxy" aus, unabhängig von
        // TRUSTED_PROXIES oben -- ein Angreifer könnte das mit einem frei
        // wählbaren, gefälschten Host-Header erzwingen und darüber
        // X-Forwarded-For beliebig fälschen, was die IP-basierten
        // throttle-Limits (routes/auth.php) aushebeln würde. TrustHosts
        // läuft in Laravels globaler Middleware-Liste VOR TrustProxies und
        // schließt damit beide Lücken: ein nicht passender Host-Header wird
        // schon hier abgelehnt (400), bevor TrustProxies ihn zu Gesicht
        // bekommt. Siehe App\Support\TrustedHosts für die Muster (zentrale
        // Domain + ihre Subdomains + je Mandant konfigurierte Custom-
        // Domains aus tenant_branding) -- subdomains: false, weil
        // TrustedHosts::patterns() das Subdomain-Muster bereits selbst
        // liefert (eigene CENTRAL_DOMAIN-Logik statt Laravels APP_URL-
        // basierter allSubdomainsOfApplicationUrl()).
        //
        // TrustedHosts::patterns(...) (PHP-First-Class-Callable-Syntax)
        // statt [TrustedHosts::class, 'patterns']: Illuminate\Http\
        // Middleware\TrustHosts::hosts() prüft per match(true) ZUERST
        // is_array($at), DANACH erst is_callable($at) -- ein Array-Callable
        // IST aber selbst ein Array und würde deshalb fälschlich als
        // LITERALE Host-Liste (['App\Support\TrustedHosts', 'patterns'])
        // behandelt statt aufgerufen zu werden, was zu einem ungültigen
        // Regex-Muster führt (der Klassenname selbst landet als "Host-
        // Pattern"). Ein echtes Closure-Objekt ist dagegen kein Array und
        // nimmt zuverlässig den is_callable-Zweig.
        $middleware->trustHosts(at: TrustedHosts::patterns(...), subdomains: false);

        $middleware->web(append: [
            SecurityHeaders::class,
            EnforceMaintenanceMode::class,
            EnsureAccountIsActive::class,
            ResolveTenant::class,
        ]);

        // Bug-Review R4-1: SecurityHeaders stand bisher ohne eigenen
        // Prioritätseintrag nur per append() in der web-Gruppe -- Laravels
        // Sortierung reiht nicht priorisierte Middleware HINTER jede
        // explizit priorisierte ein (hier u. a. ResolveTenant,
        // ThrottleRequests, VerifyCsrfToken). SecurityHeaders lag damit
        // INNERHALB dieser Middleware, statt sie zu umschließen -- eine
        // frühe Antwort von dort (Tenant-Picker/tenant.not-found aus
        // ResolveTenant, 419 aus VerifyCsrfToken, 429 aus ThrottleRequests)
        // erreichte den Code in SecurityHeaders::handle() nie, der die
        // Header erst NACH next($request) setzt -- diese Antworten kamen
        // komplett ohne CSP/X-Frame-Options/... beim Client an. Der feste
        // Platz ganz vorne in der Prioritätsliste macht SecurityHeaders zur
        // äußersten Middleware für jeden Request, unabhängig davon, welche
        // andere Middleware früh abbricht.
        $middleware->prependToPriorityList(
            before: HandlePrecognitiveRequests::class,
            prepend: SecurityHeaders::class,
        );

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
