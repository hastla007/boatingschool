<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review: mehrere Filterparameter kamen ungeprüft aus der Query-String
 * direkt in ein where(...)/whereIn(...) gegen eine uuid-Spalte -- ein
 * manipulierter, nicht-UUID-förmiger Wert (z. B. von Hand in die URL
 * eingetragen) ließ Postgres mit "invalid input syntax for type uuid"
 * scheitern. Das endete als unbehandelte QueryException mit HTTP 500,
 * statt den Filter einfach zu ignorieren -- analog zum bereits
 * behandelten Fall anmeldung_from/anmeldung_to (siehe AdminDashboardController).
 */
class ManipulatedFilterParamsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_video_course_page_ignores_an_invalid_kapitel_filter_instead_of_erroring(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video?kapitel=foo");

        $response->assertStatus(200);
    }

    public function test_video_course_page_ignores_a_kapitel_filter_submitted_as_an_array(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video?kapitel[]=foo");

        $response->assertStatus(200);
    }

    public function test_admin_dashboard_ignores_an_invalid_anmeldung_course_filter_instead_of_erroring(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=praxis-anmeldungen&anmeldung_course=foo');

        $response->assertStatus(200);
    }

    public function test_superadmin_coupons_index_ignores_an_invalid_tenant_id_filter_instead_of_erroring(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->get('/superadmin/coupons?tenant_id=foo');

        $response->assertStatus(200);
    }
}
