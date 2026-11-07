<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ExamRuleSnapshotTest extends TestCase
{
    use InteractsWithTenants;

    private array $courseIds = [];

    private array $ruleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();
        $this->onAdmin(function () {
            ExamSession::whereIn('rule_set_id', $this->ruleSetIds)->delete();
            ExamRuleSet::whereIn('id', $this->ruleSetIds)->delete();
            CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->courseIds)->delete();
        });
        $this->cleanUpCreatedUsers();
        parent::tearDown();
    }

    public function test_editing_a_rule_set_does_not_change_past_results_or_cut_running_exams(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper, $ruleSet] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'R7-'.uniqid(), 'name' => 'R7', 'course_type' => 'full', 'status' => 'published']);
            $this->courseIds[] = $course->id;
            $qs = Module::where('code', 'SBF_BASIS')->firstOrFail()->questions()
                ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published')->where('question_type', 'single_choice'))
                ->orderBy('content_question.content_id')->take(2)->get();
            $rs = ExamRuleSet::create(['course_id' => $course->id, 'version' => 'r7', 'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.5], 'status' => 'published', 'verified_at' => now()]);
            $this->ruleSetIds[] = $rs->id;
            $paper = ExamPaper::create(['course_id' => $course->id, 'paper_number' => 1, 'sort_order' => 1]);
            foreach ($qs as $i => $q) {
                ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $q->id, 'position' => $i + 1]);
            }

            return [$course, $paper, $rs];
        });
        $this->grantEntitlement($tenant, $learner, $course);

        // Attempt 1: one right, one wrong -> passed under 50% rule.
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        $sqs = $this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->get());
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", ['position' => 1, 'answer_id' => $sqs[0]->revision->correctAnswer()->id]);
        $wrong = $sqs[1]->revision->answers->firstWhere('is_correct', false);
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", ['position' => 2, 'answer_id' => $wrong->id]);
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");
        $session = $this->asTenant($tenant, fn () => $session->fresh());
        $this->assertTrue($session->passed);

        // Superadmin later edits the published rule set in place (as ExamRuleSetController::update does).
        $this->onAdmin(fn () => ExamRuleSet::whereKey($ruleSet->id)->first()->update([
            'passing_rule' => ['min_correct_ratio' => 0.5, 'parts' => [['label' => 'Basisfragen', 'module' => 'SBF_BASIS', 'min_correct' => 2, 'of' => 2]]],
            'time_limit_seconds' => 600,
        ]));

        $res = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}/result");
        $res->assertOk();
        $parts = $res->viewData('partResults');

        $this->assertSame([], $parts, 'Ergebnis bleibt bei den Regeln, unter denen geprüft wurde');

        // Laufende Prüfung: unter 60 Minuten gestartet, danach wird das Limit auf 10 Minuten gesenkt.
        $this->onAdmin(fn () => ExamRuleSet::whereKey($ruleSet->id)->update(['time_limit_seconds' => 3600]));
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $running = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->where('status', 'running')->firstOrFail());
        $this->onAdmin(fn () => ExamSession::whereKey($running->id)->update(['started_at' => now()->subMinutes(15)]));
        $this->onAdmin(fn () => ExamRuleSet::whereKey($ruleSet->id)->update(['time_limit_seconds' => 600]));
        $r = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$running->id}");
        $running = $this->asTenant($tenant, fn () => $running->fresh());

        $this->assertSame('running', $running->status, 'laufende Prüfung behält ihr Zeitlimit');
    }
}
