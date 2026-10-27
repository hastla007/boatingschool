<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Bug-Review Betrieb: der eingebaute /up-Healthcheck (siehe bootstrap/
 * app.php, health: '/up') feuert nur das DiagnosingHealth-Event und
 * lieferte ohne eigenen Listener 200, solange PHP selbst noch Requests
 * annimmt -- unabhängig davon, ob die Datenbank erreichbar ist
 * (AppServiceProvider::configureHealthCheck() schließt diese Lücke).
 * Zusätzlich lehnte TrustHosts (Sicherheitsreview R3-2/R4-A) den
 * Healthcheck mit 400 ab, wenn der Host-Header eine rohe IP-Adresse war
 * (typisch für Load-Balancer-/Orchestrator-Probes, per echtem `php
 * artisan serve` + curl mit APP_ENV=production nachgestellt und
 * verifiziert) -- App\Http\Middleware\TrustHosts nimmt /up davon gezielt
 * aus, ohne die Host-Validierung für andere Routen zu schwächen.
 *
 * Illuminate\Http\Middleware\TrustHosts::shouldSpecifyTrustedHosts()
 * schließt die Validierung in der Testumgebung explizit aus
 * (!runningUnitTests()) -- ein einfaches app()['env'] = 'production'
 * innerhalb eines Tests reicht dafür in der Praxis NICHT zuverlässig aus
 * (gegen echtes APP_ENV=production per Server-Prozess verifiziert), die
 * Host-Validierung selbst wird deshalb hier direkt auf Middleware-Ebene
 * geprüft statt über einen vollen HTTP-Testclient-Request.
 */
class HealthCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        // Bug-Review Testisolation: TrustHosts schreibt per
        // Request::setTrustedHosts() einen PROZESSWEITEN, statischen
        // Zustand (Symfony\Component\HttpFoundation\Request) -- ohne
        // diesen Reset blieben alle nachfolgenden Tests in diesem Prozess
        // auf die hier gesetzten Muster beschränkt.
        Request::setTrustedHosts([]);
        app()['env'] = 'testing';

        parent::tearDown();
    }

    public function test_up_returns_200_when_the_database_is_reachable(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }

    public function test_up_returns_a_server_error_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('DB down'));

        $response = $this->get('/up');

        $response->assertServerError();
    }

    public function test_up_itself_skips_host_validation_at_the_middleware_level(): void
    {
        app()['env'] = 'production';

        $middleware = new TrustHosts(app());
        $request = Request::create('/up', 'GET', server: ['HTTP_HOST' => '203.0.113.5']);

        $middleware->handle($request, fn ($r) => $r);

        $this->assertSame([], Request::getTrustedHosts());
    }

    /**
     * Bug-Review Betrieb: die Ausnahme gilt gezielt nur für /up -- für
     * jede andere Route muss TrustHosts weiterhin exakt wie die
     * Basisklasse Request::setTrustedHosts() aufrufen (die eigentliche
     * Ablehnung passiert erst verzögert, sobald später etwas
     * $request->getHost() aufruft -- hier direkt über den beobachtbaren
     * Zustand geprüft statt über eine konkrete Route, die getHost() u. U.
     * gar nicht aufruft).
     */
    public function test_other_routes_still_get_host_validation_applied(): void
    {
        app()['env'] = 'production';

        $middleware = new TrustHosts(app());
        $request = Request::create('/dashboard', 'GET', server: ['HTTP_HOST' => '203.0.113.5']);

        $middleware->handle($request, fn ($r) => $r);

        $this->assertNotEmpty(Request::getTrustedHosts());
    }
}
