<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Praxisformulare (siehe PraxisFormTemplate) sind plattformweite, im
 * Superadmin gepflegte Formulare für die Anmeldung zu "Praxis & Prüfung" --
 * amtlich/kursgebunden statt bootsschulspezifisch. Ein Kurs darf nur einem
 * aktiven Formular gleichzeitig zugewiesen sein (Exklusivität).
 */
class PraxisFormTemplateTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function trackTemplate(PraxisFormTemplate $template): PraxisFormTemplate
    {
        $this->createdTemplateIds[] = $template->id;

        return $template;
    }

    /**
     * Isolierter Test-Kurs statt eines echten Katalogkurses -- die echten
     * Kurse sind inzwischen alle einem Demo-Praxisformular zugewiesen
     * (siehe PraxisFormTemplateSeeder), sodass Tests, die selbst ein
     * Formular zuweisen wollen, sonst in Exklusivitäts-Konflikte liefen.
     */
    private function makeIsolatedCourse(): CourseDefinition
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PFT-'.uniqid(),
                'name' => 'Test-Kurs für Praxisformulare',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
    }

    public function test_superadmin_can_create_a_praxis_form_template(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeIsolatedCourse();

        $response = $this->actingAs($superadmin)->post('/superadmin/praxis-form-templates', [
            'title' => 'E2E Praxisformular',
            'min_progress_percent' => '50',
            'course_ids' => [$course->id],
            'fields' => [
                ['label' => 'Telefonnummer', 'type' => 'text', 'required' => '1'],
                ['label' => 'Geschlecht', 'type' => 'radio', 'required' => '1', 'options' => "Männlich\nWeiblich\nDivers"],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('praxis_form_template', ['title' => 'E2E Praxisformular', 'min_progress_percent' => 50]);

        $template = $this->trackTemplate(PraxisFormTemplate::where('title', 'E2E Praxisformular')->first());
        $this->assertSame([$course->id], $template->course_ids);
        $this->assertCount(2, $template->fields);
        $this->assertSame('telefonnummer', $template->fields[0]['key']);
        $this->assertSame(['Männlich', 'Weiblich', 'Divers'], $template->fields[1]['options']);
    }

    public function test_a_course_cannot_be_assigned_to_two_active_templates(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeIsolatedCourse();

        $this->trackTemplate($this->onAdmin(fn () => PraxisFormTemplate::create([
            'title' => 'E2E Erstes Formular',
            'min_progress_percent' => 50,
            'course_ids' => [$course->id],
            'fields' => [],
            'active' => true,
        ])));

        $response = $this->actingAs($superadmin)->post('/superadmin/praxis-form-templates', [
            'title' => 'E2E Zweites Formular',
            'min_progress_percent' => 30,
            'course_ids' => [$course->id],
        ]);

        $response->assertSessionHasErrors('course_ids');
        $this->assertDatabaseMissing('praxis_form_template', ['title' => 'E2E Zweites Formular']);
    }

    public function test_a_deactivated_templates_course_can_be_reassigned(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeIsolatedCourse();

        $old = $this->trackTemplate($this->onAdmin(fn () => PraxisFormTemplate::create([
            'title' => 'E2E Altes Formular',
            'min_progress_percent' => 50,
            'course_ids' => [$course->id],
            'fields' => [],
            'active' => false,
        ])));

        $response = $this->actingAs($superadmin)->post('/superadmin/praxis-form-templates', [
            'title' => 'E2E Neues Formular',
            'min_progress_percent' => 40,
            'course_ids' => [$course->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('praxis_form_template', ['title' => 'E2E Neues Formular']);
        $this->trackTemplate(PraxisFormTemplate::where('title', 'E2E Neues Formular')->first());
    }

    public function test_superadmin_can_update_a_template_keeping_its_own_course_assignment(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeIsolatedCourse();
        $template = $this->trackTemplate($this->onAdmin(fn () => PraxisFormTemplate::create([
            'title' => 'E2E Formular',
            'min_progress_percent' => 50,
            'course_ids' => [$course->id],
            'fields' => [],
            'active' => true,
        ])));

        $response = $this->actingAs($superadmin)->patch("/superadmin/praxis-form-templates/{$template->id}", [
            'title' => 'E2E Formular (aktualisiert)',
            'min_progress_percent' => 60,
            'course_ids' => [$course->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('praxis_form_template', ['id' => $template->id, 'title' => 'E2E Formular (aktualisiert)', 'min_progress_percent' => 60]);
    }

    public function test_superadmin_can_enable_the_tauglichkeit_hint(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeIsolatedCourse();

        $response = $this->actingAs($superadmin)->post('/superadmin/praxis-form-templates', [
            'title' => 'E2E Formular mit Tauglichkeit',
            'min_progress_percent' => '50',
            'requires_tauglichkeit' => '1',
            'course_ids' => [$course->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('praxis_form_template', ['title' => 'E2E Formular mit Tauglichkeit', 'requires_tauglichkeit' => true]);
        $this->trackTemplate(PraxisFormTemplate::where('title', 'E2E Formular mit Tauglichkeit')->first());
    }

    public function test_requires_tauglichkeit_defaults_to_false(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeIsolatedCourse();

        $this->actingAs($superadmin)->post('/superadmin/praxis-form-templates', [
            'title' => 'E2E Formular ohne Tauglichkeit',
            'min_progress_percent' => '50',
            'course_ids' => [$course->id],
        ]);

        $this->assertDatabaseHas('praxis_form_template', ['title' => 'E2E Formular ohne Tauglichkeit', 'requires_tauglichkeit' => false]);
        $this->trackTemplate(PraxisFormTemplate::where('title', 'E2E Formular ohne Tauglichkeit')->first());
    }

    public function test_superadmin_can_toggle_and_delete_a_template(): void
    {
        $superadmin = $this->createSuperAdmin();
        $template = $this->trackTemplate($this->onAdmin(fn () => PraxisFormTemplate::create([
            'title' => 'E2E Formular',
            'min_progress_percent' => 50,
            'course_ids' => [],
            'fields' => [],
            'active' => true,
        ])));

        $this->actingAs($superadmin)->post("/superadmin/praxis-form-templates/{$template->id}/toggle-active")->assertRedirect();
        $this->assertDatabaseHas('praxis_form_template', ['id' => $template->id, 'active' => false]);

        $this->actingAs($superadmin)->delete("/superadmin/praxis-form-templates/{$template->id}")->assertRedirect();
        $this->assertDatabaseMissing('praxis_form_template', ['id' => $template->id]);
        $this->createdTemplateIds = array_diff($this->createdTemplateIds, [$template->id]);
    }

    /**
     * Bug-Review N10: praxis_form_submission.praxis_form_template_id hat
     * keinen ON DELETE CASCADE/SET NULL -- ein Löschversuch bei bereits
     * bestehenden Anmeldungen verletzte den FK-Constraint und ergab einen
     * HTTP 500 statt einer verständlichen Fehlermeldung.
     */
    public function test_deleting_a_template_with_existing_submissions_fails_gracefully_instead_of_a_500(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse();
        // Bewusst NICHT trackTemplate(): die Anmeldung unten muss vor dem
        // Formular gelöscht werden, sonst scheitert bereits das
        // Standard-tearDown() (löscht Formulare vor Tenants/Nutzern) am
        // selben FK-Constraint, den dieser Test gerade prüft.
        $template = $this->onAdmin(fn () => PraxisFormTemplate::create([
            'title' => 'E2E Formular mit Anmeldung',
            'min_progress_percent' => 50,
            'course_ids' => [$course->id],
            'fields' => [],
            'active' => true,
        ]));
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));

        try {
            $response = $this->actingAs($superadmin)->delete("/superadmin/praxis-form-templates/{$template->id}");

            $response->assertSessionHasErrors('template');
            $this->assertFalse($response->isServerError());
            $this->assertDatabaseHas('praxis_form_template', ['id' => $template->id]);
        } finally {
            $this->onAdmin(function () use ($submission, $template) {
                PraxisFormSubmission::where('id', $submission->id)->delete();
                PraxisFormTemplate::where('id', $template->id)->delete();
            });
        }
    }
}
