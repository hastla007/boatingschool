<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Kombikurs SRC & UBI: die gespiegelten Bögen laufen nach dem Regelwerk
 * ihres Teilkurses -- SRC-Bögen (1-12) mit 19 von 24 in 30 Minuten,
 * UBI-Bögen (101-112) mit 17 von 22 in 60 Minuten. Das eigene Regelwerk
 * des Kombikurses bleibt draft (eine einzige Grenze passt auf keine der
 * beiden Teilprüfungen), die Zufallsprüfung bleibt damit gesperrt.
 */
class SrcUbiComboExamTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_src_paper_uses_the_src_rule_set_and_passes_with_exactly_19_of_24(): void
    {
        $src = $this->onAdmin(fn () => $this->existingCourse('SRC')->activeExamRuleSet());

        $passed = $this->takeComboPaper(1, 24, 19);
        $this->assertSame($src->id, $passed->rule_set_id);
        $this->assertSame(1800, $passed->ruleSet->time_limit_seconds);
        $this->assertTrue($passed->passed, '19 von 24 muss im SRC-Teil bestehen.');

        $this->assertFalse($this->takeComboPaper(1, 24, 18)->passed, '18 von 24 darf im SRC-Teil nicht bestehen.');
    }

    public function test_ubi_paper_uses_the_ubi_rule_set_and_passes_with_exactly_17_of_22(): void
    {
        $ubi = $this->onAdmin(fn () => $this->existingCourse('UBI')->activeExamRuleSet());

        $passed = $this->takeComboPaper(101, 22, 17);
        $this->assertSame($ubi->id, $passed->rule_set_id);
        $this->assertSame(3600, $passed->ruleSet->time_limit_seconds);
        $this->assertTrue($passed->passed, '17 von 22 muss im UBI-Teil bestehen.');

        $this->assertFalse($this->takeComboPaper(101, 22, 16)->passed, '16 von 22 darf im UBI-Teil nicht bestehen.');
    }

    public function test_papers_overview_shows_start_buttons_and_the_pass_mark_of_each_group(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        [$srcPaper, $ubiPaper] = $this->onAdmin(fn () => [
            ExamPaper::where('course_id', $course->id)->where('paper_number', 1)->firstOrFail(),
            ExamPaper::where('course_id', $course->id)->where('paper_number', 101)->firstOrFail(),
        ]);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");

        $response->assertOk();
        $response->assertDontSee('noch nicht freigegeben');
        $response->assertSee(route('exam.papers.start', ['course' => $course, 'paper' => $srcPaper]), false);
        $response->assertSee(route('exam.papers.start', ['course' => $course, 'paper' => $ubiPaper]), false);
        $response->assertSee('Bestanden ab 19 von 24 richtigen Antworten · 30 Minuten');
        $response->assertSee('Bestanden ab 17 von 22 richtigen Antworten · 60 Minuten');
    }

    public function test_course_page_offers_the_exam_for_the_combo(): void
    {
        $course = $this->onAdmin(fn () => $this->existingCourse('SRC-UBI'));

        $this->assertTrue($this->onAdmin(fn () => $course->hasStartableExam()));
        $this->assertFalse($this->onAdmin(fn () => $this->existingCourse('SBF-SEE-BIN')->hasStartableExam()));
    }

    public function test_combo_own_rule_set_stays_draft_so_the_random_exam_is_still_blocked(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->assertFalse($this->onAdmin(fn () => ExamRuleSet::where('course_id', $course->id)->firstOrFail()->isVerified()));
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam")->assertStatus(409);
    }

    public function test_sbf_combo_papers_stay_blocked_because_their_part_courses_are_unverified(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $learner, $course);

        foreach ([1, 101] as $paperNumber) {
            $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', $paperNumber)->firstOrFail());

            $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertStatus(409);
        }
    }

    private function takeComboPaper(int $paperNumber, int $expectedQuestions, int $correct): ExamSession
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', $paperNumber)->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();

        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return [$session, $session->questions()->orderBy('position')->with('revision.answers')->get()];
        });
        $this->assertSame($course->id, $session->course_id);
        $this->assertCount($expectedQuestions, $sessionQuestions);

        foreach ($sessionQuestions as $i => $sq) {
            $answer = $sq->revision->answers->firstWhere('is_correct', $i < $correct);
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $answer->id,
            ]);
        }

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish")->assertRedirect();

        return $this->asTenant($tenant, fn () => $session->fresh());
    }
}
