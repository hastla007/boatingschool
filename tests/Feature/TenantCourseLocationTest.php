<?php

namespace Tests\Feature;

use App\Models\TenantCourseLocation;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Praxis-/Prüfungsort-Paare (siehe TenantCourseLocation) pflegt jede
 * Bootsschule selbst im "Orte"-Tab -- der Kunde wählt im Praxisformular
 * immer den Praxisort, der Prüfungsort ist fix daran gekoppelt.
 */
class TenantCourseLocationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_school_admin_can_create_a_location_with_a_different_exam_location(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Standort Binnensee',
            'pruefung_ort' => 'Prüfstelle Hafenstadt',
            'course_ids' => [$course->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_course_location', [
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort Binnensee',
            'pruefung_ort' => 'Prüfstelle Hafenstadt',
        ]);

        $location = TenantCourseLocation::where('tenant_id', $tenant->id)->first();
        $this->assertSame([$course->id], $location->course_ids);
    }

    public function test_pruefung_ort_defaults_to_praxis_ort_when_left_empty(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Hauptstandort',
            'course_ids' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_course_location', [
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Hauptstandort',
            'pruefung_ort' => 'Hauptstandort',
        ]);
    }

    public function test_a_location_can_target_multiple_courses(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');

        $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Hauptstandort',
            'course_ids' => [$courseA->id, $courseB->id],
        ]);

        $location = TenantCourseLocation::where('tenant_id', $tenant->id)->first();
        $this->assertEqualsCanonicalizing([$courseA->id, $courseB->id], $location->course_ids);
    }

    public function test_a_school_admin_can_update_and_delete_a_location(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Alt',
            'pruefung_ort' => 'Alt',
            'course_ids' => [],
        ]));

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/tenant-course-locations/{$location->id}", [
            'praxis_ort' => 'Neu',
            'course_ids' => [],
        ])->assertRedirect();
        $this->assertDatabaseHas('tenant_course_location', ['id' => $location->id, 'praxis_ort' => 'Neu']);

        $this->actingAsInTenant($admin, $tenant)->delete("/admin/tenant-course-locations/{$location->id}")->assertRedirect();
        $this->assertDatabaseMissing('tenant_course_location', ['id' => $location->id]);
    }

    public function test_a_school_admin_cannot_modify_another_tenants_location(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenantA->id,
            'praxis_ort' => 'Nur A',
            'pruefung_ort' => 'Nur A',
            'course_ids' => [],
        ]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->patch("/admin/tenant-course-locations/{$location->id}", [
            'praxis_ort' => 'Übernommen',
            'course_ids' => [],
        ]);

        $response->assertNotFound();
        $this->onAdmin(fn () => $this->assertDatabaseHas('tenant_course_location', ['id' => $location->id, 'praxis_ort' => 'Nur A']));
    }

    public function test_orte_tab_lists_locations(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'E2E Sichtbarer Ort',
            'pruefung_ort' => 'E2E Sichtbarer Ort',
            'course_ids' => [],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=orte');

        $response->assertOk();
        $response->assertSee('E2E Sichtbarer Ort');
    }
}
