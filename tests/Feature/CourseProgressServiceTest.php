<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Models\Module;
use App\Models\Progress;
use App\Services\CourseProgressService;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review F7: eine vom Superadmin deaktivierte Frage taucht im
 * Smarttrainer, im Favoriten-Fragenpool und in der Prüfung nicht mehr auf
 * (siehe SmarttrainerService, FavoriteController, ExamController) -- sie
 * zählte im Kursfortschritt bisher aber weiter im Nenner mit. Da sie so
 * nie "gefestigt" werden konnte, waren 100% Kursfortschritt dauerhaft
 * unerreichbar, und die darauf aufbauenden Freigaben (Praxisformular,
 * Willkommensbox, Addon-/Banner-Empfehlungen) blieben gesperrt.
 */
class CourseProgressServiceTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
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

    /** @return array{0: CourseDefinition, 1: ContentQuestion, 2: ContentQuestion, 3: ContentQuestion} */
    private function makeCourseWithTwoActiveAndOneDisabledQuestion(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-CPS-'.uniqid(),
                'name' => 'Test-Kurs für Fortschrittsberechnung',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create([
                'code' => 'TEST-CPS-MODULE-'.uniqid(),
                'name' => 'Testmodul',
                'version' => '1',
                'status' => 'published',
            ]);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);

            $activeA = ContentQuestion::create(['content_id' => 'TEST-CPS-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $activeB = ContentQuestion::create(['content_id' => 'TEST-CPS-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $disabled = ContentQuestion::create(['content_id' => 'TEST-CPS-Q-'.uniqid(), 'language' => 'de-DE', 'active' => false]);
            $this->createdQuestionIds = [$activeA->id, $activeB->id, $disabled->id];

            $module->questions()->attach($activeA->id, ['sort_order' => 1, 'required' => true]);
            $module->questions()->attach($activeB->id, ['sort_order' => 2, 'required' => true]);
            $module->questions()->attach($disabled->id, ['sort_order' => 3, 'required' => true]);

            return [$course, $activeA, $activeB, $disabled];
        });
    }

    public function test_overall_percent_reaches_100_with_a_disabled_question_in_the_denominator(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $activeA, $activeB] = $this->makeCourseWithTwoActiveAndOneDisabledQuestion();

        $this->onAdmin(function () use ($tenant, $learner, $activeA, $activeB) {
            foreach ([$activeA, $activeB] as $question) {
                Progress::create([
                    'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                    'learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
                ]);
            }
        });

        $percent = $this->asTenant($tenant, fn () => app(CourseProgressService::class)->overallPercent($course, $tenant, $learner));

        $this->assertSame(100, $percent);
    }

    public function test_module_kategorie_breakdown_excludes_disabled_questions(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $activeA, $activeB] = $this->makeCourseWithTwoActiveAndOneDisabledQuestion();

        $this->onAdmin(function () use ($tenant, $learner, $activeA, $activeB) {
            foreach ([$activeA, $activeB] as $question) {
                Progress::create([
                    'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                    'learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
                ]);
            }
        });

        $breakdown = $this->asTenant($tenant, fn () => app(CourseProgressService::class)->moduleKategorieBreakdown($course, $tenant, $learner));

        $this->assertSame(2, $breakdown->first()['total']);
        $this->assertSame(100, $breakdown->first()['percent']);
    }

    /**
     * Bug-Review F7: dieselbe Formel ist aus Performance-Gründen ein
     * zweites Mal in CourseController::courseMasteryPercent() dupliziert
     * (treibt dort u. a. die Addon-/Banner-Freigabe auf der Kursseite) --
     * hatte denselben Fehler unabhängig von CourseProgressService.
     */
    public function test_course_page_banner_is_shown_once_100_percent_is_reached_despite_a_disabled_question(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $activeA, $activeB] = $this->makeCourseWithTwoActiveAndOneDisabledQuestion();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant, $learner, $course, $activeA, $activeB) {
            foreach ([$activeA, $activeB] as $question) {
                Progress::create([
                    'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                    'learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
                ]);
            }

            CoursePromoBanner::create([
                'tenant_id' => $tenant->id,
                'active' => true,
                'headline' => 'Nur bei 100% sichtbar',
                'offer_url' => 'https://shop.example.test/x',
                'course_ids' => [$course->id],
                'trigger_percent' => 100,
            ]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Nur bei 100% sichtbar');
    }

    /**
     * Bug-Review F7: dieselbe Formel ist aus Performance-Gründen (Bug-
     * Review P1) ein drittes Mal in Superadmin\UserController::show()
     * dupliziert -- hatte denselben Fehler unabhängig von den beiden
     * anderen Stellen.
     */
    public function test_superadmin_user_page_shows_100_percent_despite_a_disabled_question(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $activeA, $activeB] = $this->makeCourseWithTwoActiveAndOneDisabledQuestion();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant, $learner, $activeA, $activeB) {
            foreach ([$activeA, $activeB] as $question) {
                Progress::create([
                    'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                    'learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
                ]);
            }
        });

        $response = $this->actingAs($superadmin)->get("/superadmin/users/{$learner->id}");

        $response->assertOk();
        $response->assertSee('100%');
    }
}
