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

    public function test_a_location_stores_coordinates_and_falls_back_to_praxis_coordinates(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Standort Nord',
            'praxis_lat' => '53.5460740',
            'praxis_lng' => '9.9826940',
            'pruefung_ort' => 'Prüfstelle Süd',
            'pruefung_lat' => '48.1351250',
            'pruefung_lng' => '11.5819810',
            'course_ids' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('tenant_course_location', [
            'tenant_id' => $tenant->id,
            'praxis_lat' => 53.5460740,
            'praxis_lng' => 9.9826940,
            'pruefung_lat' => 48.1351250,
            'pruefung_lng' => 11.5819810,
        ]);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Standort West',
            'praxis_lat' => '51.2277400',
            'praxis_lng' => '6.7734560',
            'course_ids' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('tenant_course_location', [
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Standort West',
            'pruefung_lat' => 51.2277400,
            'pruefung_lng' => 6.7734560,
        ]);
    }

    public function test_coordinates_are_rejected_outside_valid_ranges(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Standort Ungültig',
            'praxis_lat' => '200',
            'praxis_lng' => '9.98',
            'course_ids' => [],
        ]);

        $response->assertSessionHasErrors('praxis_lat');
        $this->assertDatabaseMissing('tenant_course_location', ['praxis_ort' => 'Standort Ungültig']);
    }

    public function test_a_location_stores_separate_addresses_for_praxis_and_pruefung(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Standort Binnensee',
            'praxis_strasse' => 'Seeweg 1',
            'praxis_plz' => '12345',
            'praxis_stadt' => 'Binnenstadt',
            'praxis_land' => 'Deutschland',
            'pruefung_ort' => 'Prüfstelle Hafenstadt',
            'pruefung_strasse' => 'Hafenallee 9',
            'pruefung_plz' => '54321',
            'pruefung_stadt' => 'Hafenstadt',
            'pruefung_land' => 'Österreich',
            'course_ids' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_course_location', [
            'tenant_id' => $tenant->id,
            'praxis_strasse' => 'Seeweg 1',
            'praxis_plz' => '12345',
            'praxis_stadt' => 'Binnenstadt',
            'praxis_land' => 'Deutschland',
            'pruefung_strasse' => 'Hafenallee 9',
            'pruefung_plz' => '54321',
            'pruefung_stadt' => 'Hafenstadt',
            'pruefung_land' => 'Österreich',
        ]);
    }

    public function test_pruefung_address_defaults_to_praxis_address_when_left_empty(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Hauptstandort',
            'praxis_strasse' => 'Musterweg 1',
            'praxis_plz' => '11111',
            'praxis_stadt' => 'Musterstadt',
            'praxis_land' => 'Deutschland',
            'course_ids' => [],
        ]);

        $this->assertDatabaseHas('tenant_course_location', [
            'tenant_id' => $tenant->id,
            'pruefung_strasse' => 'Musterweg 1',
            'pruefung_plz' => '11111',
            'pruefung_stadt' => 'Musterstadt',
            'pruefung_land' => 'Deutschland',
        ]);
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
