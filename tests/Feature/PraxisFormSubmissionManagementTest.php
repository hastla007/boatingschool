<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantCourseLocation;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review F6: Praxis-Anmeldungen waren bisher nach dem Absenden rein
 * lesend -- weder ein Tippfehler der Bootsschule beim Weiterreichen an die
 * Prüfungskommission noch eine versehentliche/nicht mehr benötigte
 * Anmeldung ließen sich korrigieren; eine gelöschte Anmeldung blockierte
 * über den Unique-Index (tenant_id, user_id, course_id) sogar jede neue
 * Anmeldung desselben Lerners für denselben Kurs.
 */
class PraxisFormSubmissionManagementTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    private function makeIsolatedCourse(): CourseDefinition
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PFSM-'.uniqid(),
                'name' => 'Test-Kurs für Anmeldungsverwaltung',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
    }

    private function createTemplate(array $overrides = []): PraxisFormTemplate
    {
        return $this->onAdmin(function () use ($overrides) {
            $template = PraxisFormTemplate::create(array_merge([
                'title' => 'E2E Verwaltungs-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [],
                'fields' => [
                    ['key' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
                ],
                'active' => true,
            ], $overrides));
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
    }

    public function test_admin_can_correct_a_submitted_value(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse();
        $template = $this->createTemplate(['course_ids' => [$course->id]]);
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 0000000'],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch(
            route('admin.praxis-form-submissions.update', $submission),
            ['telefonnummer' => '+49 170 1234567']
        );

        $response->assertRedirect(route('admin.praxis-form-submissions.show', $submission));
        $this->onAdmin(function () use ($submission) {
            $this->assertSame('+49 170 1234567', $submission->fresh()->data['telefonnummer']);
        });
    }

    public function test_admin_cannot_correct_a_submission_of_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $learnerA = $this->createTenantUser($tenantA, 'learner');
        $course = $this->makeIsolatedCourse();
        $template = $this->createTemplate(['course_ids' => [$course->id]]);
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenantA->id,
            'course_id' => $course->id,
            'user_id' => $learnerA->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 0000000'],
        ]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->patch(
            route('admin.praxis-form-submissions.update', $submission),
            ['telefonnummer' => '+49 170 9999999']
        );

        $response->assertNotFound();
        $this->onAdmin(function () use ($submission) {
            $this->assertSame('+49 170 0000000', $submission->fresh()->data['telefonnummer']);
        });
    }

    public function test_the_edit_form_prefills_the_currently_selected_location(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse();
        $location = $this->onAdmin(fn () => TenantCourseLocation::create([
            'tenant_id' => $tenant->id,
            'course_ids' => [$course->id],
            'praxis_ort' => 'Hafen Nord',
            'pruefung_ort' => 'Prüfzentrum Nord',
        ]));
        $template = $this->createTemplate([
            'course_ids' => [$course->id],
            'fields' => [['key' => 'ort', 'label' => 'Ort', 'type' => 'location', 'required' => true, 'options' => [], 'help_text' => null]],
        ]);
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['ort' => 'Hafen Nord', 'ort_location_id' => $location->id],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.praxis-form-submissions.edit', $submission));

        $response->assertOk();
        $response->assertSee('selected', false);
    }

    public function test_admin_can_delete_a_submission_and_the_learner_can_then_submit_again(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse();
        $template = $this->createTemplate(['course_ids' => [$course->id]]);
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 0000000'],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->delete(route('admin.praxis-form-submissions.destroy', $submission));

        $response->assertRedirect(route('admin.dashboard', ['tab' => 'praxis-anmeldungen']));
        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $this->assertDatabaseMissing('praxis_form_submission', [
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
            ]);
        });

        $this->grantEntitlement($tenant, $learner, $course);
        $this->completeRegistrationProfile($learner, $tenant);
        $second = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'telefonnummer' => '+49 170 1234567',
        ]);
        $second->assertRedirect(route('praxis-pruefung.index', $course));
    }

    /** Bug-Review F6: das Löschen muss den Terminvorschlag kaskadiert mit entfernen, sonst bliebe ein verwaister Datensatz zurück. */
    public function test_deleting_a_submission_also_removes_its_exam_appointments(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse();
        $template = $this->createTemplate(['course_ids' => [$course->id]]);
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 0000000'],
        ]));
        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => now()->addMonth()->format('Y-m-d H:i'), 'slot' => 0]
        );

        $this->actingAsInTenant($admin, $tenant)->delete(route('admin.praxis-form-submissions.destroy', $submission));

        $this->onAdmin(function () use ($submission) {
            $this->assertSame(0, PraxisExamAppointment::where('praxis_form_submission_id', $submission->id)->count());
        });
    }

    public function test_admin_cannot_delete_a_submission_of_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $learnerA = $this->createTenantUser($tenantA, 'learner');
        $course = $this->makeIsolatedCourse();
        $template = $this->createTemplate(['course_ids' => [$course->id]]);
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenantA->id,
            'course_id' => $course->id,
            'user_id' => $learnerA->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['telefonnummer' => '+49 170 0000000'],
        ]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->delete(route('admin.praxis-form-submissions.destroy', $submission));

        $response->assertNotFound();
        $this->onAdmin(fn () => $this->assertDatabaseHas('praxis_form_submission', ['id' => $submission->id]));
    }
}
