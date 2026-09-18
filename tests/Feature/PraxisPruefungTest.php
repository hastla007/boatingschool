<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\Progress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PraxisPruefungTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert progress weg), dann die isolierten
        // Test-Fragen/-Modul/-Kurs (in dieser Reihenfolge wegen FKs).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            // Kurs zuerst löschen (kaskadiert course_module weg), dann das
            // Modul (kaskadiert module_content weg), erst danach können die
            // referenzierten Fragen gelöscht werden.
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

    public function test_booking_page_requires_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxis-pruefung");

        $response->assertForbidden();
    }

    public function test_booking_page_is_forbidden_below_the_default_fifty_percent_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);

        // 0 von 2 Fragen gefestigt = 0% < 50% Standard-Voraussetzung.
        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxis-pruefung");

        $response->assertForbidden();
    }

    public function test_booking_page_is_reachable_once_the_default_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, , $questionIds] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);

        // 1 von 2 Fragen gefestigt = 50% >= 50% Standard-Voraussetzung.
        $this->masterQuestions($tenant, $learner, array_slice($questionIds, 0, 1));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxis-pruefung");

        $response->assertOk();
        $response->assertSee('Praxis & Prüfung');
    }

    public function test_each_school_can_configure_its_own_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, , $questionIds] = $this->makeIsolatedCourseWithQuestions(10);
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update(['exam_readiness_threshold_percent' => 10]));

        // 1 von 10 Fragen gefestigt = 10% -- reicht bei dieser Schule (10%),
        // hätte beim Standardwert (50%) nicht gereicht.
        $this->masterQuestions($tenant, $learner, array_slice($questionIds, 0, 1));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxis-pruefung");

        $response->assertOk();
    }

    public function test_the_course_overview_shows_the_tile_greyed_out_below_the_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Praxis & Prüfung');
        $response->assertSee('Ab 50% Kursfortschritt', false);
        $response->assertDontSee(route('praxis-pruefung.index', $course), false);
    }

    public function test_the_course_overview_links_the_tile_once_the_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, , $questionIds] = $this->makeIsolatedCourseWithQuestions(2);
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterQuestions($tenant, $learner, array_slice($questionIds, 0, 1));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Jetzt buchbar');
        $response->assertSee(route('praxis-pruefung.index', $course), false);
    }

    private function masterQuestions($tenant, $learner, array $questionIds): void
    {
        $this->onAdmin(function () use ($tenant, $learner, $questionIds) {
            foreach ($questionIds as $questionId) {
                Progress::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'question_id' => $questionId,
                    'attempt_count' => 3,
                    'correct_count' => 3,
                    'incorrect_count' => 0,
                    'current_streak' => 3,
                    'mastery_score' => 1,
                    'learning_state' => 'gefestigt',
                ]);
            }
        });
    }

    /** @return array{0: CourseDefinition, 1: Module, 2: array<int, string>} */
    private function makeIsolatedCourseWithQuestions(int $count): array
    {
        return $this->onAdmin(function () use ($count) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PP-'.uniqid(),
                'name' => 'Test Praxis-Prüfung-Kurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create([
                'code' => 'TEST-PP-MODULE-'.uniqid(),
                'name' => 'Testmodul',
                'version' => '1',
                'status' => 'published',
            ]);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);

            $questionIds = [];
            for ($i = 0; $i < $count; $i++) {
                $question = ContentQuestion::create([
                    'content_id' => 'TEST-PP-Q-'.uniqid(),
                    'language' => 'de-DE',
                    'active' => true,
                ]);
                $this->createdQuestionIds[] = $question->id;
                $questionIds[] = $question->id;
                $module->questions()->attach($question->id, ['sort_order' => $i + 1, 'required' => true]);
            }

            return [$course, $module, $questionIds];
        });
    }
}
