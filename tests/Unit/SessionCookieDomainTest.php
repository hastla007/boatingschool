<?php

namespace Tests\Unit;

use App\Support\SessionCookieDomain;
use PHPUnit\Framework\TestCase;

/**
 * Bug-Review R3-1 (Folgefehler): ohne einen geteilten Session-Cookie-
 * Domain-Scope setzt ein Login auf einer Bootsschul-Subdomain das Cookie
 * NUR für genau diese Subdomain (Browser-Standardverhalten ohne
 * explizites Domain-Attribut) -- ein bereits eingeloggter Nutzer, der
 * einen Einladungs-Link für eine ANDERE Bootsschule öffnet, schickt das
 * Cookie dort nie mit und erscheint sich selbst als nicht eingeloggt,
 * unabhängig vom app.current_user_id-RLS-Fix in ResolveTenant (der nur
 * innerhalb EINES Cookies wirkt). config/session.php leitet den
 * Cookie-Scope jetzt aus CENTRAL_DOMAIN ab; diese Ableitung selbst ist
 * hier direkt getestet (siehe App\Support\SessionCookieDomain für die
 * Begründung von (env() ?: null) ?? statt env()s zweitem Argument).
 */
class SessionCookieDomainTest extends TestCase
{
    public function test_an_explicitly_configured_domain_always_wins(): void
    {
        $this->assertSame('.explicit.test', SessionCookieDomain::resolve('.explicit.test', 'localhost'));
        $this->assertSame('.explicit.test', SessionCookieDomain::resolve('.explicit.test', 'boatingschool.example'));
    }

    public function test_localhost_and_127_0_0_1_keep_the_unchanged_null_behaviour(): void
    {
        $this->assertNull(SessionCookieDomain::resolve(null, 'localhost'));
        $this->assertNull(SessionCookieDomain::resolve('', 'localhost'));
        $this->assertNull(SessionCookieDomain::resolve(null, '127.0.0.1'));
    }

    public function test_a_real_central_domain_derives_a_leading_dot_domain_for_subdomain_sharing(): void
    {
        $this->assertSame('.boatingschool.example', SessionCookieDomain::resolve(null, 'boatingschool.example'));
        $this->assertSame('.boatingschool.example', SessionCookieDomain::resolve('', 'boatingschool.example'));
    }
}
