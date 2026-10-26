<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bug-Review R3-1 (Folgefehler): config/session.php leitet den Session-
 * Cookie-Scope jetzt aus CENTRAL_DOMAIN ab (siehe
 * tests/Unit/SessionCookieDomainTest.php für die reine Ableitungslogik).
 * Dieser Test prüft zusätzlich das tatsächlich beim Client ankommende
 * Set-Cookie-Domain-Attribut -- also genau das, worauf der Browser beim
 * Entscheiden, ob er das Cookie auf einer ANDEREN Bootsschul-Subdomain
 * mitsendet, sich stützt.
 */
class SessionCookieDomainFeatureTest extends TestCase
{
    public function test_the_session_cookie_carries_the_derived_domain_for_a_real_central_domain(): void
    {
        config(['session.domain' => '.boatingschule-e2e.test']);

        $response = $this->get('/login');

        $cookie = $this->findSessionCookie($response);

        $this->assertNotNull($cookie, 'Session-Cookie wurde nicht gesetzt.');
        $this->assertSame('.boatingschule-e2e.test', $cookie->getDomain());
    }

    public function test_the_session_cookie_has_no_domain_attribute_for_the_unchanged_localhost_default(): void
    {
        config(['session.domain' => null]);

        $response = $this->get('/login');

        $cookie = $this->findSessionCookie($response);

        $this->assertNotNull($cookie, 'Session-Cookie wurde nicht gesetzt.');
        $this->assertNull($cookie->getDomain());
    }

    private function findSessionCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        return null;
    }
}
