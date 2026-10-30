<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B11: ExamController prüfte bei show()/answer()/finish()/
 * result() nur, ob die Session dem angemeldeten Nutzer gehört -- nicht, ob
 * für den zugehörigen Kurs noch ein aktives Entitlement besteht. Eine
 * laufende Prüfung ließ sich dadurch beliebig fortsetzen (und ein Ergebnis
 * weiter einsehen), selbst nachdem der Kurszugang entzogen wurde.
 */
class ExamAccessRevocationTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_a_running_exam_cannot_be_continued_after_access_is_revoked(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $question] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $question = $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail();

            return [$session, $question];
        });

        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'suspended']));

        $show = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");
        $show->assertForbidden();

        $answer = $question->revision->answers->first();
        $answerResponse = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $answer->id,
        ]);
        $answerResponse->assertForbidden();

        $session = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('running', $session->status);
    }

    public function test_a_finished_exam_result_cannot_be_viewed_after_access_is_revoked(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestions];
        });

        foreach ($sessionQuestions as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'suspended']));

        $result = $this->actingAsInTenant($learner, $tenant)->get(route('exam.result', $session));
        $result->assertForbidden();
    }

    /**
     * Bug-Review (Zufallsprüfung bei SRC/FKN hat nur 10 statt 24/15 Fragen):
     * SRC hat inzwischen echte Prüfungsbögen, die zufällig zusammengestellte
     * Prüfung (start()) ist dafür nicht mehr erreichbar (409) -- dieser Test
     * braucht aber genau diesen Weg und nutzt deshalb SKN, das keine
     * eigenen Prüfungsbögen hat (ADD-UBI-TO-SRC hat inzwischen die zwölf
     * UBI-Ergänzungsbögen), mit einem eigenen, isoliert verifizierten Rule
     * Set, das aus dem UBI-Modul zieht (nur single_choice-Fragen).
     *
     * @return array{0: Tenant, 1: User, 2: CourseDefinition}
     */
    private function setUpVerifiedExamCourse(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SKN');

        $module = $this->existingModule('UBI');
        $reviewer = $this->createTenantUser($tenant, 'admin');

        $this->onAdmin(function () use ($course, $module, $reviewer) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
            ]);

            ExamBlueprint::create([
                'rule_set_id' => $ruleSet->id,
                'module_id' => $module->id,
                'question_count' => 3,
            ]);

            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        return [$tenant, $learner, $course];
    }
}
