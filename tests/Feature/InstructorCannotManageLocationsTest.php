<?php

namespace Tests\Feature;

use App\Models\TenantCourseLocation;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview H3: Praxis-/Prüfungsorte anzulegen, zu ändern oder zu
 * löschen bleibt owner/admin vorbehalten -- ein Instructor sah die Tabelle
 * bisher zwar nur lesend gedacht, die Routen selbst waren aber auch für
 * ihn freigegeben (tenant.role:owner,admin,instructor), sodass er trotzdem
 * per direktem Request Orte anlegen/ändern/löschen konnte.
 */
class InstructorCannotManageLocationsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_an_instructor_cannot_create_a_location(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $response = $this->actingAsInTenant($instructor, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Sollte nicht entstehen',
            'course_ids' => [],
        ]);

        $response->assertForbidden();
        $this->asTenant($tenant, function () {
            $this->assertDatabaseMissing('tenant_course_location', ['praxis_ort' => 'Sollte nicht entstehen']);
        });
    }

    public function test_an_instructor_cannot_update_or_delete_a_location(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'Alt',
            'pruefung_ort' => 'Alt',
            'course_ids' => [],
        ]));

        $this->actingAsInTenant($instructor, $tenant)->patch("/admin/tenant-course-locations/{$location->id}", [
            'praxis_ort' => 'Übernommen',
            'course_ids' => [],
        ])->assertForbidden();
        $this->actingAsInTenant($instructor, $tenant)->delete("/admin/tenant-course-locations/{$location->id}")->assertForbidden();

        $this->asTenant($tenant, function () use ($location) {
            $this->assertDatabaseHas('tenant_course_location', ['id' => $location->id, 'praxis_ort' => 'Alt']);
        });
    }

    public function test_an_instructor_sees_the_orte_tab_without_mutation_forms(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'praxis_ort' => 'E2E Sichtbar',
            'pruefung_ort' => 'E2E Sichtbar',
            'course_ids' => [],
        ]));

        $response = $this->actingAsInTenant($instructor, $tenant)->get('/admin?tab=orte');

        $response->assertOk();
        $response->assertSee('E2E Sichtbar');
        $response->assertDontSee('Neuen Ort anlegen');
        $response->assertDontSee('Ort löschen');
    }

    public function test_an_owner_can_still_manage_locations(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => 'Owner-Ort',
            'course_ids' => [],
        ])->assertRedirect();

        $this->asTenant($tenant, function () use ($tenant) {
            $this->assertDatabaseHas('tenant_course_location', ['tenant_id' => $tenant->id, 'praxis_ort' => 'Owner-Ort']);
        });
    }
}
