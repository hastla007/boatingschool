<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Models\ExamSession;
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

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_running_exam_cannot_be_continued_after_access_is_revoked(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
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
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
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
}
