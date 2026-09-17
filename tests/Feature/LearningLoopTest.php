<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Progress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Abnahmekriterien: "Antworten werden serverseitig bewertet und jeder
 * Versuch wird unveränderlich gespeichert" und "Fortschritt kann aus den
 * gespeicherten Attempts rekonstruiert werden".
 */
class LearningLoopTest extends TestCase
{
    use InteractsWithTenants;

    public function test_correctness_is_computed_server_side_not_trusted_from_client(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $wrongAnswer = $revision->answers->firstWhere('is_correct', false);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $wrongAnswer->id,
            'mode' => 'smarttrainer',
            'response_time_ms' => 1000,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('attempt', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $question->id,
            'correct' => false,
        ]);
    }

    public function test_a_question_outside_the_course_cannot_be_attempted(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        // Frage aus einem Modul, das nicht Teil des SRC-Kurses ist.
        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $answer = $revision->answers->first();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $answer->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertForbidden();
    }

    public function test_every_attempt_creates_a_new_immutable_row_and_progress_is_reconstructable(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);
        $wrongAnswer = $revision->answers->firstWhere('is_correct', false);

        $endpoint = "/courses/{$course->id}/learn/attempts";
        $payload = fn ($answerId) => [
            'revision_id' => $revision->id,
            'answer_id' => $answerId,
            'mode' => 'smarttrainer',
        ];

        $this->actingAsInTenant($learner, $tenant)->post($endpoint, $payload($wrongAnswer->id))->assertOk();
        $this->actingAsInTenant($learner, $tenant)->post($endpoint, $payload($correctAnswer->id))->assertOk();

        $attempts = Attempt::where('tenant_id', $tenant->id)
            ->where('user_id', $learner->id)
            ->where('question_id', $question->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $attempts);
        $this->assertFalse($attempts[0]->correct);
        $this->assertTrue($attempts[1]->correct);

        $progress = Progress::where('tenant_id', $tenant->id)
            ->where('user_id', $learner->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $this->assertSame(2, $progress->attempt_count);
        $this->assertSame(1, $progress->correct_count);
        $this->assertSame(1, $progress->incorrect_count);
        $this->assertSame($attempts->where('correct', true)->count(), $progress->correct_count);
        $this->assertSame($attempts->where('correct', false)->count(), $progress->incorrect_count);
    }
}
