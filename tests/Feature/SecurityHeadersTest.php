<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M9: ohne diese Header ließen sich u. a. die
 * Superadmin-Formulare per Clickjacking in ein fremdes iframe einbetten
 * und es fehlte eine zusätzliche Absicherung gegen MIME-Sniffing (siehe
 * auch H4).
 */
class SecurityHeadersTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

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

    /**
     * Sicherheitsreview M9: script-src 'unsafe-inline' wurde entfernt --
     * die eigenen <script>-Blöcke bekommen stattdessen ein pro Request
     * erzeugtes Nonce (siehe SecurityHeaders::handle()), onclick=/
     * onsubmit=/onchange=-Attribute wurden zu Alpine-Direktiven. Ohne ein
     * gültiges Nonce dürfte ein Browser keinen der eigenen Inline-Scripts
     * mehr ausführen.
     */
    public function test_script_src_no_longer_allows_unsafe_inline(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $scriptSrc = collect(explode(';', $csp))->first(fn ($directive) => str_starts_with(trim($directive), 'script-src'));

        $this->assertNotNull($scriptSrc, 'script-src-Direktive nicht gefunden.');
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringContainsString("'unsafe-eval'", $scriptSrc);
    }

    public function test_style_src_intentionally_still_allows_unsafe_inline(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $styleSrc = collect(explode(';', $csp))->first(fn ($directive) => str_starts_with(trim($directive), 'style-src'));

        $this->assertNotNull($styleSrc, 'style-src-Direktive nicht gefunden.');
        $this->assertStringContainsString("'unsafe-inline'", $styleSrc);
    }

    public function test_every_inline_script_on_a_rendered_page_carries_the_requests_csp_nonce(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        // learning/question.blade.php enthält einen eigenen <script>-Block
        // (Favoriten-Toggle) -- genau der Fall, den das Nonce absichern muss.
        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn");

        $response->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", $csp, $nonceMatch);
        $this->assertNotEmpty($nonceMatch, 'Kein Nonce in der CSP gefunden.');

        preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', $response->getContent(), $scriptTags);
        $this->assertNotEmpty($scriptTags[0], 'Seite enthielt keinen eigenen <script>-Block, Test prüft nichts.');

        foreach ($scriptTags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonceMatch[1].'"', $tag, "Inline-<script>-Tag ohne (passendes) Nonce: {$tag}");
        }
    }
}
