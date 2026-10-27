<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U3: ein Login leitete bisher IMMER auf /dashboard
 * (das Lernenden-Dashboard) weiter, unabhängig von der Rolle -- ein Owner/
 * Admin/Instructor hat dort aber meist keine eigene Freischaltung und
 * landete so auf "Für dich ist noch kein Kurs freigeschaltet" statt im für
 * ihre Rolle gedachten Bootsschul-Admin-Bereich (siehe
 * AuthenticatedSessionController::store() und die Startseite in
 * routes/web.php, die denselben Pfad über redirect()->intended() erneut
 * durchlaufen kann).
 */
class LoginRedirectByRoleTest extends TestCase
{
    use InteractsWithTenants;

    public function test_an_owner_is_redirected_to_the_admin_dashboard_after_login(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => $owner->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_an_admin_is_redirected_to_the_admin_dashboard_after_login(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_an_instructor_is_redirected_to_the_admin_dashboard_after_login(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => $instructor->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_a_learner_is_still_redirected_to_the_learner_dashboard_after_login(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => $learner->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * redirect()->intended() nach einem Login kann statt der Rollen-Weiche
     * eine zuvor besuchte "/" als gemerktes Ziel verwenden -- die
     * Startseite muss deshalb dieselbe Rollen-Weiche anwenden, sonst landet
     * ein Admin trotzdem wieder auf /dashboard.
     */
    public function test_the_home_route_applies_the_same_role_based_redirect_for_an_already_authenticated_admin(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/');

        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }
}
