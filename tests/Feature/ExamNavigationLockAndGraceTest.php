<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\NavigationTask;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * QA-L-6: Die Sperre der Navigationsaufgabe einer laufenden Prüfung galt
 * nicht für die gespiegelte Kopie im Kombikurs. QA-L-8: Eine verspätete
 * Antwort im Web wertete die Prüfung auch in der Navigations-Nachfrist aus.
 */
class ExamNavigationLockAndGraceTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_the_mirrored_copy_of_the_exam_navigation_task_is_locked_too(): void
    {
        [$tenant, $learner, $session] = $this->startSeePaper();
        $combo = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $learner, $combo);
        $taskId = $this->asTenant($tenant, fn () => ExamSession::find($session->id)->navigation_task_id);
        $copy = $this->onAdmin(fn () => NavigationTask::where('course_id', $combo->id)->where('source_task_id', $taskId)->first());
        $this->assertNotNull($copy, 'Testvoraussetzung: gespiegelte Aufgabe mit Quellverweis');

        $this->actingAsInTenant($learner, $tenant)->get(route('exam.navigation.show', ['course' => $combo, 'task' => $copy->id]))
            ->assertRedirect(route('exam.show', $session));
        $this->assertSame($session->id, ExamSession::runningWithNavigationTask($tenant->id, $learner->id, $copy->id)?->id);
        // Andere Aufgaben bleiben offen.
        $otherCopy = $this->onAdmin(fn () => NavigationTask::where('course_id', $combo->id)->where('id', '!=', $copy->id)->first());
        $this->assertNull(ExamSession::runningWithNavigationTask($tenant->id, $learner->id, $otherCopy->id));
    }

    public function test_a_late_answer_during_the_navigation_grace_period_keeps_the_exam_open(): void
    {
        [$tenant, $learner, $session] = $this->startSeePaper(answerAll: false);
        $position = $this->asTenant($tenant, fn () => ExamSession::find($session->id)->questions()->whereNull('answered_at')->value('position'));
        $deadline = $this->asTenant($tenant, fn () => ExamSession::find($session->id)->deadline());

        // Prüfungszeit knapp vorbei, Nachfrist der Navigation läuft noch.
        $this->travelTo($deadline->copy()->addSeconds(10));
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", ['position' => $position, 'answer_id' => null])
            ->assertRedirect(route('exam.show', $session));

        $fresh = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('running', $fresh->status, 'Die Navigation muss in der Nachfrist noch abgegeben werden können.');
        $this->assertNull($this->asTenant($tenant, fn () => $fresh->questions()->where('position', $position)->value('answered_at')));

        // Nach der Nachfrist wird ausgewertet.
        $this->travelTo($deadline->copy()->addSeconds(ExamSession::NAVIGATION_SUBMIT_GRACE + 15));
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", ['position' => $position, 'answer_id' => null])
            ->assertRedirect(route('exam.result', $session));
        $this->assertSame('evaluated', $this->asTenant($tenant, fn () => ExamSession::find($session->id)->status));
    }

    /** @return array{0: Tenant, 1: User, 2: ExamSession} */
    private function startSeePaper(bool $answerAll = true): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 3)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());
        $questions = $this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->get());
        foreach ($answerAll ? $questions : $questions->slice(1) as $sq) {
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position, 'answer_id' => $this->answerToken($learner, $sq->revision->answers->firstWhere('is_correct', true)->id),
            ]);
        }

        return [$tenant, $learner, $session];
    }
}
