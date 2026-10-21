<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sicherheitsreview M9: ohne diese Header ließen sich u. a. die
 * Superadmin-Formulare per Clickjacking in ein fremdes iframe einbetten
 * und es fehlte eine zusätzliche Absicherung gegen MIME-Sniffing (siehe
 * auch H4).
 */
class SecurityHeadersTest extends TestCase
{
    public function test_responses_carry_the_hardening_headers(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('Content-Security-Policy');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_hsts_is_only_sent_over_an_actually_secure_request(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $response = $this->get('https://'.parse_url(config('app.url'), PHP_URL_HOST).'/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_content_security_policy_allows_the_leaflet_map_assets(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://unpkg.com', $csp);
        $this->assertStringContainsString('https://*.tile.openstreetmap.org', $csp);
    }
}
