<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\Module;
use App\Models\Progress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Folgefehler BB4): der "Prüfungsaufgaben"-Banner auf dem
 * Dashboard galt schon als verfügbar, sobald ein Kurs Prüfungsbögen hatte
 * -- auch ohne freigegebenes Regelwerk (z. B. SBF-BIN-SEGEL mit
 * Entwurfs-Regelwerk), sodass kein Bogen startbar war. Maßgeblich ist
 * CourseDefinition::hasStartableExam().
 */
class DashboardExamBannerRequiresStartableExamTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

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

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_papers_without_a_released_rule_set_do_not_trigger_the_exam_banner(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        [$course, $question] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-DASH-EXAM-'.uniqid(),
                'name' => 'Test Kurs Dashboard',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create(['code' => 'TEST-DASH-MOD-'.uniqid(), 'name' => 'Test Modul Dashboard', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id);

            $question = ContentQuestion::create(['content_id' => 'TEST-DASH-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);
            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id, 'revision_no' => 1, 'question_type' => 'single_choice',
                'question_text' => 'Dashboard-Testfrage', 'editorial_status' => 'published', 'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Ja', 'is_correct' => true, 'sort_order' => 1]);

            // Prüfungsbogen vorhanden, aber kein (freigegebenes) Regelwerk.
            ExamPaper::create(['course_id' => $course->id, 'paper_number' => 1, 'sort_order' => 1]);

            return [$course, $question];
        });

        $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Progress::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
            'attempt_count' => 3, 'correct_count' => 3, 'incorrect_count' => 0, 'current_streak' => 3,
            'mastery_score' => 1, 'learning_state' => 'gefestigt',
        ]));

        $this->assertFalse($course->hasStartableExam());

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')
            ->assertOk()
            ->assertViewHas('courses', fn ($summaries) => $summaries->firstWhere('course.id', $course->id)['banner_mode'] === 'smart');
    }
}
