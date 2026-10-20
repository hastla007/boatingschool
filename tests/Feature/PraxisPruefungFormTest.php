<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Progress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Praxis & Prüfung" zeigt je nach zugewiesenem Praxisformular (siehe
 * PraxisFormTemplate) entweder den Platzhalter (kein Formular zugewiesen),
 * eine Hürden-Meldung (Kursfortschritt unter min_progress_percent), das
 * dynamische Formular, oder nach dem Absenden eine Bestätigung.
 */
class PraxisPruefungFormTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        // Tenants zuerst aufräumen (kaskadiert praxis_form_submission weg),
        // erst danach die global geteilten Formular-Vorlagen löschen --
        // sonst schlägt das wegen der FK auf noch existierende
        // Einreichungen fehl.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    /**
     * Isolierter Test-Kurs mit eigenen Fragen statt eines echten
     * Katalogkurses -- die echten Kurse sind inzwischen alle einem Demo-
     * Praxisformular zugewiesen (siehe PraxisFormTemplateSeeder), sodass
     * Tests, die selbst ein Formular zuweisen, sonst in
     * Exklusivitäts-Konflikte liefen.
     *
     * @return array{0: CourseDefinition, 1: array<int, string>}
     */
    private function makeIsolatedCourseWithQuestions(int $count): array
    {
        return $this->onAdmin(function () use ($count) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PPF-'.uniqid(),
                'name' => 'Test-Kurs für Praxis & Prüfung',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create([
                'code' => 'TEST-PPF-MODULE-'.uniqid(),
                'name' => 'Testmodul',
                'version' => '1',
                'status' => 'published',
            ]);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);

            $questionIds = [];
            for ($i = 0; $i < $count; $i++) {
                $question = ContentQuestion::create([
                    'content_id' => 'TEST-PPF-Q-'.uniqid(),
                    'language' => 'de-DE',
                    'active' => true,
                ]);
                $this->createdQuestionIds[] = $question->id;
                $questionIds[] = $question->id;
                $module->questions()->attach($question->id, ['sort_order' => $i + 1, 'required' => true]);
            }

            return [$course, $questionIds];
        });
    }

    private function createTemplate(array $overrides = []): PraxisFormTemplate
    {
        return $this->onAdmin(function () use ($overrides) {
            $template = PraxisFormTemplate::create(array_merge([
                'title' => 'E2E Praxisformular',
                'min_progress_percent' => 50,
                'course_ids' => [],
                'fields' => [
                    ['key' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'text', 'required' => true, 'options' => [], 'help_text' => null],
                    ['key' => 'geschlecht', 'label' => 'Geschlecht', 'type' => 'radio', 'required' => true, 'options' => ['Männlich', 'Weiblich', 'Divers'], 'help_text' => null],
                    ['key' => 'tauglichkeit', 'label' => 'Ich habe bereits die Tauglichkeit', 'type' => 'checkbox', 'required' => false, 'options' => [], 'help_text' => null],
                ],
                'active' => true,
            ], $overrides));
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
    }

    private function masterAllQuestions($tenant, $learner, $course): void
    {
        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

            foreach ($questionIds as $questionId) {
                Progress::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionId],
                    ['learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1]
                );
            }
        });
    }

    public function test_shows_placeholder_without_an_assigned_template(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        // Ohne zugewiesenes Formular gilt weiter die bootsschulweite
        // Branding-Schwelle (Standard 50%) -- hier auf 0 gesetzt, um nur
        // das "kein Formular zugewiesen"-Verhalten isoliert zu prüfen.
        $this->onAdmin(fn () => $tenant->branding->update(['exam_readiness_threshold_percent' => 0]));

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('wird in Kürze verfügbar sein');
    }

    public function test_shows_the_threshold_gate_below_the_required_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 50]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee('Du erfüllst noch nicht alle Voraussetzungen');
        $response->assertSee('50% Kursfortschritt');
    }

    public function test_shows_the_dynamic_form_once_the_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));

        $response->assertOk();
        $response->assertSee($template->title);
        $response->assertSee('Telefonnummer');
        $response->assertSee('Geschlecht');
    }

    public function test_learner_can_submit_the_form_and_sees_a_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);
        $template = $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'telefonnummer' => '+49 170 1234567',
            'geschlecht' => 'Männlich',
            'tauglichkeit' => '1',
        ]);

        $response->assertRedirect(route('praxis-pruefung.index', $course));

        $this->onAdmin(function () use ($tenant, $learner, $course, $template) {
            $this->assertDatabaseHas('praxis_form_submission', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'praxis_form_template_id' => $template->id,
            ]);
        });

        $follow = $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course));
        $follow->assertOk();
        $follow->assertSee('Deine Anmeldung ist eingegangen.');
        $follow->assertSee('+49 170 1234567');
        $follow->assertSee('Männlich');
    }

    public function test_submission_requires_required_fields(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 10]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), []);

        $response->assertSessionHasErrors(['telefonnummer', 'geschlecht']);
        $this->onAdmin(fn () => $this->assertDatabaseMissing('praxis_form_submission', ['tenant_id' => $tenant->id, 'user_id' => $learner->id]));
    }

    public function test_submission_is_rejected_below_the_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->createTemplate(['course_ids' => [$course->id], 'min_progress_percent' => 50]);

        $response = $this->actingAsInTenant($learner, $tenant)->post(route('praxis-pruefung.store', $course), [
            'telefonnummer' => '+49 170 1234567',
            'geschlecht' => 'Männlich',
        ]);

        $response->assertForbidden();
    }
}
