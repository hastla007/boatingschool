<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Module;
use App\Models\NavigationProgress;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ContentGuardsTest extends TestCase
{
    use InteractsWithTenants;

    private array $courses = [];

    private array $modules = [];

    private array $questions = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            ExamSession::withoutGlobalScopes()->whereIn('course_id', $this->courses)->delete();
            CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->courses)->delete();
            DB::table('module_content')->whereIn('question_id', $this->questions)->delete();
            ExamBlueprint::whereIn('module_id', $this->modules)->delete();
            Module::whereIn('id', $this->modules)->delete();
            ContentQuestion::whereIn('id', $this->questions)->delete();
            AuditLog::whereIn('actor_user_id', $this->createdUserIds)->delete();
        });
        parent::tearDown();
    }

    private function setUpCourse(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'R8TEST-G-'.uniqid(), 'name' => 'R8 Guard', 'course_type' => 'full', 'status' => 'published', 'site_enabled' => true]);
            $module = Module::create(['code' => 'R8TEST_M_'.uniqid(), 'name' => 'R8 Modul', 'version' => '1', 'status' => 'published']);
            $this->courses[] = $course->id;
            $this->modules[] = $module->id;
            $course->modules()->attach($module->id, ['sort_order' => 1, 'required' => true]);
            $qs = [];
            foreach ([1, 2] as $n) {
                $q = ContentQuestion::create(['content_id' => 'R8TEST-G-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
                $this->questions[] = $q->id;
                $r = ContentQuestionRevision::create(['question_id' => $q->id, 'revision_no' => 1, 'question_type' => 'single_choice', 'question_text' => "R8 Frage $n", 'editorial_status' => 'published', 'rights_status' => 'licensed']);
                foreach (['A', 'B', 'C', 'D'] as $i => $k) {
                    ContentAnswer::create(['revision_id' => $r->id, 'answer_key' => $k, 'answer_text' => $k, 'is_correct' => $k === 'A', 'sort_order' => $i + 1]);
                }
                $module->questions()->attach($q->id, ['required' => true]);
                $qs[] = $q;
            }
            $rs = ExamRuleSet::create(['course_id' => $course->id, 'version' => 'r8', 'time_limit_seconds' => 600, 'passing_rule' => ['min_correct_ratio' => 0.5], 'status' => 'published', 'verified_at' => now()]);
            ExamBlueprint::create(['rule_set_id' => $rs->id, 'module_id' => $module->id, 'question_count' => 2]);

            return [$course, $module, $qs];
        });
    }

    /** Alle Fragen des Blueprint-Moduls deaktiviert -> Prüfung startet mit 0 Fragen; Modul aus dem Kurs entfernt -> Prüfung zieht weiter daraus. Keine Warnung. */
    public function test_exam_modules_cannot_be_detached_and_empty_exams_are_not_started(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$course, $module, $qs] = $this->setUpCourse();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        $this->grantEntitlement($tenant, $learner, $course);

        // Modul im Fragenmix lässt sich nicht abhängen.
        $this->actingAs($superadmin)->delete("/superadmin/courses/{$course->id}/modules/{$module->id}")->assertSessionHasErrors('module');

        foreach ($qs as $q) {
            $this->actingAs($superadmin)->post("/superadmin/questions/{$q->id}/toggle-active")->assertSessionHasNoErrors();
        }
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam")->assertStatus(409);
        $this->assertFalse($this->onAdmin(fn () => ExamSession::withoutGlobalScopes()->where('user_id', $learner->id)->exists()), 'keine leere Prüfung angelegt');
    }

    /** Musterlösung korrigiert -> Lernende, die gegen die falsche Lösung "richtig" hatten, behalten "erledigt". */
    public function test_correcting_a_navigation_model_answer_resets_old_marks(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$course] = $this->setUpCourse();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        [$task, $nq] = $this->onAdmin(function () use ($course) {
            $t = NavigationTask::create(['course_id' => $course->id, 'task_number' => 1, 'scenario_text' => 'R8 Szenario', 'sort_order' => 1]);
            $q = NavigationQuestion::create(['navigation_task_id' => $t->id, 'question_number' => 1, 'question_text' => 'Kurs?', 'answer_text' => 'rwK = 090°', 'sort_order' => 1]);

            return [$t, $q];
        });
        $this->onAdmin(fn () => NavigationProgress::create(['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'navigation_question_id' => $nq->id, 'answer' => '090', 'correct' => true]));

        $this->actingAs($superadmin)->patch("/superadmin/navigation-tasks/{$task->id}", [
            'scenario_text' => 'R8 Szenario', 'questions' => [['id' => $nq->id, 'question_text' => 'Kurs?', 'answer_text' => 'rwK = 270°']],
        ])->assertSessionHasNoErrors();

        $p = $this->onAdmin(fn () => NavigationProgress::where('navigation_question_id', $nq->id)->first());
        $this->assertNull($p->correct, 'alte Bewertung gilt nach geänderter Musterlösung nicht mehr');
        $this->assertSame('090', $p->answer, 'eigene Antwort bleibt erhalten');
        $this->assertSame('rwK = 270°', $this->onAdmin(fn () => $nq->fresh()->answer_text));
    }
}
