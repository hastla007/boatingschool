<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review: 'verified' fehlte auf beiden Admin-Routengruppen (anders als
 * auf der regulären Lerner-Gruppe, siehe routes/web.php) -- ein
 * Owner/Admin/Instructor mit noch nicht bestätigter E-Mail-Adresse konnte
 * trotzdem Teilnehmer einladen, Entitlements/Coupons verwalten und
 * Branding ändern (Phishing-Risiko über das Schul-Branding), ohne je
 * nachgewiesen zu haben, dass die Adresse tatsächlich ihm gehört.
 *
 * Bug-Review R4-J: dieselbe Lücke bestand auch auf der noch mächtigeren
 * Superadmin-Routengruppe (routes/superadmin.php) -- EnsureSuperAdmin
 * prüft nur is_superadmin, nicht email_verified_at.
 */
class AdminRoutesRequireVerifiedEmailTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_an_unverified_owner_is_redirected_away_from_the_admin_dashboard(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner', ['email_verified_at' => null]);

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_instructor_is_redirected_away_from_the_admin_dashboard(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor', ['email_verified_at' => null]);

        $response = $this->actingAsInTenant($instructor, $tenant)->get('/admin');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_admin_cannot_update_branding(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin', ['email_verified_at' => null]);

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
        ]);

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_owner_can_still_reach_the_admin_dashboard(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertOk();
    }

    public function test_an_unverified_superadmin_is_redirected_away_from_the_superadmin_area(): void
    {
        $superadmin = $this->createSuperAdmin(['email_verified_at' => null]);

        $response = $this->actingAs($superadmin)->get('/superadmin');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_superadmin_can_still_reach_the_superadmin_area(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get('/superadmin');

        $response->assertOk();
    }

    /**
     * Bug-Review NEU-2 (Folgefehler): verify-email* fehlte in
     * ResolveTenant::isTenantAgnosticRoute() -- auf der zentralen Domain
     * (kein Tenant auflösbar) landete ein unbestätigter Nutzer beim
     * Redirect der 'verified'-Middleware deshalb nicht auf der
     * Bestätigungsseite, sondern auf dem Tenant-Picker (siehe
     * ResolveTenant::handle(), Zweig ohne aufgelösten Tenant) -- ein
     * unbestätigter Superadmin konnte sich auf der zentralen Domain damit
     * gar nicht mehr verifizieren.
     */
    public function test_an_unverified_superadmin_can_reach_the_verification_prompt_on_the_central_domain(): void
    {
        $superadmin = $this->createSuperAdmin(['email_verified_at' => null]);

        $response = $this->actingAs($superadmin)->get('/verify-email');

        $response->assertOk();
        $response->assertViewIs('auth.verify-email');
    }
}
