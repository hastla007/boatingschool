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

    /**
     * Bug-Review R4-1: SecurityHeaders stand bisher ohne eigenen
     * Prioritätseintrag nur per append() in der web-Gruppe -- Laravel
     * reiht nicht priorisierte Middleware hinter jede explizit
     * priorisierte ein (siehe bootstrap/app.php), SecurityHeaders lag damit
     * INNERHALB von ResolveTenant/ThrottleRequests/VerifyCsrfToken statt
     * sie zu umschließen. Eine frühe Antwort direkt aus einer dieser
     * Middleware (Tenant-Picker/tenant.not-found, 429) erreichte den
     * Header-setzenden Code in SecurityHeaders::handle() deshalb nie.
     */
    public function test_the_tenant_picker_carries_the_hardening_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_an_unresolvable_tenant_host_carries_the_hardening_headers(): void
    {
        $response = $this->get('http://unknown-tenant.'.config('app.central_domain').'.invalid/');

        $response->assertNotFound();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_a_throttled_response_carries_the_hardening_headers(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->post('/login', ['email' => 'nope-'.$i.'@example.test', 'password' => 'wrong']);
        }

        $response->assertStatus(429);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_hsts_is_only_sent_over_an_actually_secure_request(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $response = $this->get('https://'.parse_url(config('app.url'), PHP_URL_HOST).'/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_content_security_policy_allows_the_map_tiles_but_no_longer_the_whole_cdn(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://*.tile.openstreetmap.org', $csp);

        // Bug-Review M9 (Folgefehler): Leaflet selbst kam früher vom
        // unpkg.com-CDN -- die CSP musste dafür die GANZE Domain erlauben
        // (CSP kennt nur Origin-, keine Pfad-Einschränkungen), nicht nur
        // dieses eine Paket. Jetzt per npm/Vite gebündelt, unpkg.com ist
        // nirgends mehr nötig.
        $this->assertStringNotContainsString('unpkg.com', $csp);
    }

    /**
     * Bug-Review (Hoch): MediaAsset::storagePath() liefert eine ABSOLUTE,
     * fest auf den APP_URL-Host verankerte URL (config/filesystems.php,
     * 'url' => env('APP_URL').'/storage') -- auf einer Bootsschul-
     * Subdomain oder Custom Domain ist das ein ANDERER Origin als die
     * aufgerufene Seite. img-src 'self' allein ließ nur den EIGENEN
     * Origin zu, der Browser blockierte Fragen-/Praxis-/Logo-Bilder dort
     * unabhängig davon, dass der Server sie korrekt auslieferte -- die
     * Testsuite lief bisher immer unter genau dem APP_URL-Host, weshalb
     * das nie auffiel.
     */
    public function test_the_content_security_policy_allows_images_from_the_app_url_host_on_any_requested_host(): void
    {
        $csp = $this->get('http://schule-a.example.test/login')->headers->get('Content-Security-Policy');

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $this->assertNotSame(
            'schule-a.example.test',
            $appHost,
            'Testvoraussetzung: der aufgerufene Host muss sich vom APP_URL-Host unterscheiden, sonst prüft dieser Test nichts.'
        );

        $this->assertStringContainsString(
            $appHost,
            $csp,
            'img-src muss den APP_URL-Host auch dann erlauben, wenn die Seite selbst über einen ANDEREN Host aufgerufen wird -- sonst werden Fragen-/Praxis-/Logo-Bilder dort vom Browser blockiert.'
        );
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
