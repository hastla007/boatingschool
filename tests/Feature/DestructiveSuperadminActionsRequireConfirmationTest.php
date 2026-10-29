<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin "Modul entfernen" und "Sperren" wirken ohne
 * Rückfrage): drei destruktive/einschneidende Ein-Klick-Aktionen hatten
 * bisher keinen Bestätigungsdialog -- anders als "Ort löschen" (siehe
 * admin/tabs/orte.blade.php), das als Vorbild für das hier verwendete
 * Alpine-confirm()-Muster dient.
 */
class DestructiveSuperadminActionsRequireConfirmationTest extends TestCase
{
    use InteractsWithTenants;

    /** @var array<int, string> */
    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        foreach ($this->createdCourseIds as $id) {
            $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete());
        }

        parent::tearDown();
    }

    public function test_removing_a_module_from_a_course_asks_for_confirmation(): void
    {
        $superadmin = $this->createSuperAdmin();
        $module = $this->existingModule('SRC');

        $courseResponse = $this->actingAs($superadmin)->post('/superadmin/courses', [
            'code' => 'E2E-CONFIRM-TEST',
            'name' => 'E2E Bestätigungs-Test',
            'course_type' => 'full',
            'status' => 'published',
        ]);
        $courseResponse->assertRedirect();

        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'E2E-CONFIRM-TEST')->firstOrFail();
        $this->createdCourseIds[] = $course->id;

        $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/modules", [
            'module_id' => $module->id,
        ]);

        $response = $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee("confirm('Dieses Modul wirklich aus dem Kurs entfernen?')", false);
    }

    public function test_suspending_an_entitlement_through_the_participant_page_asks_for_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee("confirm('Diesen Zugang wirklich sperren?')", false);
    }

    public function test_suspending_a_membership_in_the_participants_list_asks_for_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants');

        $response->assertOk();
        $response->assertSee("confirm('Dieses Konto wirklich sperren?')", false);
    }
}
