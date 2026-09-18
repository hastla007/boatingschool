<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Bootsschul-Admin" und der Superadmin-Rücksprung ("Login auf Plattform")
 * stehen bewusst im Konto-Dropdown ("<Name> ▾") statt in der primären
 * Nav-Leiste -- dort tauchen nur die für alle Lernenden relevanten Links
 * (Lernen, Kurse, Coupon-Code einlösen) auf.
 */
class NavigationMenuTest extends TestCase
{
    use InteractsWithTenants;

    public function test_bootsschul_admin_link_is_in_the_account_dropdown_not_the_primary_nav(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Bootsschul-Admin');
        // Die primäre Nav-Leiste enthält nur die drei allgemeinen Links.
        $response->assertSeeInOrder(['Lernen', 'Kurse', 'Coupon-Code einlösen', $admin->name, 'Bootsschul-Admin']);
    }

    public function test_learner_never_sees_the_bootsschul_admin_link(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Bootsschul-Admin');
    }

    public function test_superadmin_with_a_tenant_membership_sees_a_platform_login_link(): void
    {
        $tenant = $this->createTestTenant();
        $superadminMember = $this->createTenantUser($tenant, 'learner', ['is_superadmin' => true]);

        $response = $this->actingAsInTenant($superadminMember, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Login auf Plattform');
        $response->assertSee(route('superadmin.dashboard', absolute: false), false);
    }

    public function test_regular_learner_does_not_see_the_platform_login_link(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Login auf Plattform');
    }
}
