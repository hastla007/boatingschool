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

class ExamPaperTileCountTest extends TestCase
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

    public function test_paper_tile_counts_the_questions_of_the_taken_paper(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'R7T-'.uniqid(), 'name' => 'R7', 'course_type' => 'full', 'status' => 'published']);
            $this->courseIds[] = $course->id;
            $qs = Module::where('code', 'SBF_BASIS')->firstOrFail()->questions()
                ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published')->where('question_type', 'single_choice'))
                ->orderBy('content_question.content_id')->take(3)->get();
            $rs = ExamRuleSet::create(['course_id' => $course->id, 'version' => 'r7', 'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.5], 'status' => 'published', 'verified_at' => now()]);
            $this->ruleSetIds[] = $rs->id;
            $paper = ExamPaper::create(['course_id' => $course->id, 'paper_number' => 1, 'sort_order' => 1]);
            foreach ($qs as $i => $q) {
                ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $q->id, 'position' => $i + 1]);
            }

            return [$course, $paper];
        });
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        $sqs = $this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->get());
        foreach ($sqs as $sq) {
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", ['position' => $sq->position, 'answer_id' => $sq->revision->correctAnswer()->id]);
        }
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        // Later one question stops counting for the paper (here: removed from the isolated paper;
        // in production the same happens when a superadmin deactivates it or valid_to passes).
        $this->onAdmin(fn () => ExamPaperQuestion::where('exam_paper_id', $paper->id)->where('position', 3)->delete());

        $stats = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam")->viewData('paperStats');
        $this->assertSame(3, $stats[0]['correct']);
        $this->assertSame(3, $stats[0]['total'], 'Kachel zeigt die Fragenzahl des abgelegten Bogens, nicht „3/2“');
    }
}
