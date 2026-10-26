<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review NEU-1 (Regression, Folgefehler von R3-1): config/session.php
 * leitete den Session-Cookie-Scope automatisch aus CENTRAL_DOMAIN ab
 * (".<CENTRAL_DOMAIN>"), sobald dieser keine lokale Entwicklungsumgebung
 * war -- ein Tenant mit eigener Custom Domain (siehe
 * ResolveTenant::resolveViaCustomDomain()) liegt aber NICHT unter
 * CENTRAL_DOMAIN. Der Browser verwarf das Set-Cookie mit diesem, zum
 * aufgerufenen Host nicht passenden Domain-Attribut komplett (RFC 6265),
 * die Session ging dadurch bei JEDEM Request verloren -- am echten
 * Server verifiziert als 419 auf POST /login (CSRF-Prüfung ist in
 * PHPUnit-Testläufen selbst bewusst deaktiviert, siehe
 * VerifyCsrfToken::runningUnitTests(), ein 419 lässt sich hier also nicht
 * reproduzieren -- das zugrunde liegende, tatsächlich entscheidende
 * Set-Cookie-Domain-Attribut aber schon).
 *
 * config('session.domain') wird einmalig beim App-Boot aus der .env
 * berechnet -- ein config(['app.central_domain' => ...])-Aufruf INNERHALB
 * eines Tests wirkt sich darauf nicht mehr aus (anders als vor dem Fix,
 * wo diese Ableitung überhaupt existierte). Um die alte wie die neue
 * Ableitungslogik unabhängig vom bereits gebooteten Config-Cache direkt
 * zu vergleichen, bindet dieser Test config/session.php -- wie
 * LogRotationConfigurationTest config/logging.php -- mit bewusst
 * kontrolliertem Prozess-Environment frisch neu ein.
 */
class SessionCookieDomainFeatureTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_wired_config_value_has_no_domain_attribute_even_with_a_real_central_domain_configured(): void
    {
        $envKeys = ['SESSION_DOMAIN', 'CENTRAL_DOMAIN'];
        $previous = [];
        foreach ($envKeys as $key) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }

        putenv('SESSION_DOMAIN');
        unset($_ENV['SESSION_DOMAIN'], $_SERVER['SESSION_DOMAIN']);
        putenv('CENTRAL_DOMAIN=boatingschule-e2e.test');
        $_ENV['CENTRAL_DOMAIN'] = 'boatingschule-e2e.test';
        $_SERVER['CENTRAL_DOMAIN'] = 'boatingschule-e2e.test';

        try {
            $config = require base_path('config/session.php');

            $this->assertNull(
                $config['domain'],
                'Das Session-Cookie darf nicht mehr automatisch aus CENTRAL_DOMAIN abgeleitet werden -- sonst verwirft der Browser es auf jeder Custom Domain, die nicht unter CENTRAL_DOMAIN liegt.'
            );
        } finally {
            foreach ($previous as $key => [$envValue, $serverValue]) {
                if ($envValue !== null) {
                    putenv("{$key}={$envValue}");
                    $_ENV[$key] = $envValue;
                } else {
                    putenv($key);
                    unset($_ENV[$key]);
                }

                if ($serverValue !== null) {
                    $_SERVER[$key] = $serverValue;
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    public function test_an_explicitly_configured_session_domain_still_wins(): void
    {
        config(['session.domain' => '.explizit-gesetzt.test']);

        $response = $this->get('/login');

        $cookie = $this->findSessionCookie($response);

        $this->assertNotNull($cookie, 'Session-Cookie wurde nicht gesetzt.');
        $this->assertSame('.explizit-gesetzt.test', $cookie->getDomain());
    }

    public function test_login_works_end_to_end_on_a_custom_domain(): void
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)
            ->update(['custom_domain' => 'kurse.eigene-domain-e2e.test']));
        $user = $this->createTenantUser($tenant);

        $response = $this->get('http://kurse.eigene-domain-e2e.test/login');
        $response->assertOk();

        $cookie = $this->findSessionCookie($response);
        $this->assertNotNull($cookie, 'Session-Cookie wurde nicht gesetzt.');
        $this->assertNull($cookie->getDomain());

        $loginResponse = $this->post('http://kurse.eigene-domain-e2e.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $loginResponse->assertRedirect();
    }

    private function findSessionCookie($response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        return null;
    }
}
