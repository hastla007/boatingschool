<?php

namespace Tests\Unit;

use App\Support\SessionCookieDomain;
use PHPUnit\Framework\TestCase;

/**
 * Bug-Review NEU-1 (Regression, Folgefehler von R3-1): die R3-1-Fassung
 * leitete hier automatisch ".CENTRAL_DOMAIN" ab (Cookie gilt für die
 * zentrale Domain UND alle ihre Subdomains) -- das brach Custom Domains
 * vollständig (ein Set-Cookie mit einem zum aufgerufenen Host nicht
 * passenden Domain-Attribut wird vom Browser komplett verworfen) und
 * vergrößerte unnötig die Angriffsfläche (plattformweites statt
 * Pro-Host-Cookie). Siehe App\Support\SessionCookieDomain für die
 * ausführliche Begründung der Rücknahme.
 */
class SessionCookieDomainTest extends TestCase
{
    public function test_an_explicitly_configured_domain_always_wins(): void
    {
        $this->assertSame('.explicit.test', SessionCookieDomain::resolve('.explicit.test'));
    }

    public function test_an_unset_or_empty_value_resolves_to_null_regardless_of_the_central_domain(): void
    {
        $this->assertNull(SessionCookieDomain::resolve(null));
        $this->assertNull(SessionCookieDomain::resolve(''));
    }
}
