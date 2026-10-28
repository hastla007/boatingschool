<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bug-Review T1: Illuminate\Http\Middleware\TrustProxies::at() setzt eine
 * STATISCHE Eigenschaft -- sie wird genau einmal gesetzt, wenn Laravels
 * HTTP-Kernel zum ersten Mal aufgelöst wird (Illuminate\Foundation\
 * Configuration\ApplicationBuilder::withMiddleware(),
 * "afterResolving(HttpKernel::class, ...)"), danach ist der Kernel ein
 * gecachter Singleton und löst den Callback nie wieder aus. Bei einem
 * echten php-fpm-Request ist das IMMER die allererste Auflösung dieses
 * Prozesses, lange bevor LoadConfiguration 'config' in den Container
 * gebunden hat -- TRUSTED_PROXIES aus der .env erreicht at() in diesem
 * Moment praktisch nie (siehe bootstrap/app.php für die Herleitung).
 *
 * Diese zwei Requests simulieren exakt das: der erste Request löst hier
 * -- genau wie bei einem echten, frischen php-fpm-Worker -- den
 * HTTP-Kernel zum ersten Mal auf, während TRUSTED_PROXIES noch leer ist.
 * Erst DANACH wird TRUSTED_PROXIES gesetzt (z. B. per .env + Neustart,
 * hier durch den direkten config()-Aufruf simuliert) -- der zweite Request
 * im selben Kernel-Singleton zeigt, ob die neue Konfiguration tatsächlich
 * noch wirkt. Mit der alten, boot-zeitigen at()-Logik wirkt sie nicht
 * mehr (der erste Request hat die statische Eigenschaft bereits auf leer
 * eingefroren); App\Http\Middleware\TrustProxies liest TRUSTED_PROXIES
 * stattdessen bei JEDEM Request frisch aus config().
 */
class TrustedProxiesAppliedFromConfigTest extends TestCase
{
    public function test_trusted_proxies_from_config_are_honored_even_after_an_earlier_request_already_resolved_the_kernel(): void
    {
        $this->get('/login');

        config(['app.trusted_proxies' => '10.0.0.9']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_an_untrusted_proxy_address_is_still_not_honored(): void
    {
        $this->get('/login');

        config(['app.trusted_proxies' => '10.0.0.9']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * Bug-Review (TRUSTED_PROXIES=* funktioniert nicht): proxies() zerlegte
     * den konfigurierten Wert bisher bedingungslos per
     * explode(',', ...) -- aus dem Platzhalter "*" ("allen Proxys
     * vertrauen") wurde dadurch immer das Array ['*'] statt des von der
     * Elternklasse (Illuminate\Http\Middleware\TrustProxies::
     * setTrustedProxyIpAddresses()) erwarteten literalen Strings "*". Ein
     * Array erfüllt deren Vergleich $trustedIps === '*' nie, jede beliebige
     * Proxy-Adresse wurde dadurch wie eine NICHT vertraute behandelt.
     */
    public function test_a_wildcard_trusted_proxies_value_trusts_any_proxy_address(): void
    {
        $this->get('/login');

        config(['app.trusted_proxies' => '*']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
