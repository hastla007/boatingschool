<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review M4 (Folgefehler): $request->ip() liefert hinter JEDEM
 * Reverse-Proxy (auch einem rein lokalen, z. B. nginx vor php-fpm in einem
 * Docker-Setup) ohne eine vertrauenswürdige TrustProxies-Konfiguration
 * immer die Adresse des Proxys statt des echten Clients -- der
 * Localhost-Check in ResolveTenant::resolveViaDevOverride()
 * (Sicherheitsreview M4) griff deshalb hinter einem solchen Proxy nie mehr,
 * selbst wenn der tatsächliche Client echtes Localhost war. bootstrap/app.php
 * registriert jetzt TRUSTED_PROXIES über $middleware->trustProxies(), damit
 * ein Betreiber die IP(s) des EIGENEN, bekannten Proxys eintragen kann.
 *
 * Die env()-Auswertung passiert beim App-Bootstrap (bootstrap/app.php) --
 * die Variable muss deshalb VOR dem Hochfahren der Anwendung gesetzt sein,
 * also schon in setUp() vor parent::setUp(), nicht erst im Testkörper.
 */
class TrustedProxyDevOverrideTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.9');
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.9';
        $_SERVER['TRUSTED_PROXIES'] = '10.0.0.9';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    public function test_the_dev_override_sees_the_real_localhost_client_through_a_trusted_proxy(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));

        // Simuliert exakt den gemeldeten Fall: der echte Client ist
        // Localhost, die Verbindung kommt aber über einen Reverse-Proxy
        // (REMOTE_ADDR) an, der die echte Adresse per X-Forwarded-For
        // weitergibt.
        $response = $this->actingAs($learner)
            ->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.9',
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            ])
            ->withSession(['dev_tenant_slug' => $tenant->slug])
            ->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Weiterlernen');
    }

    public function test_an_untrusted_proxy_address_is_still_not_honored(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        // Ein ANDERER Proxy als der eingetragene (10.0.0.9) darf das
        // X-Forwarded-For trotz konfigurierter TRUSTED_PROXIES nicht
        // durchreichen -- sonst ließe sich der Dev-Override von jeder nicht
        // vertrauenswürdigen Quelle aus per gefälschtem Header umgehen.
        $response = $this->actingAs($learner)
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.5',
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            ])
            ->withSession(['dev_tenant_slug' => $tenant->slug])
            ->get('/dashboard');

        $response->assertDontSee('Weiterlernen');
    }
}
