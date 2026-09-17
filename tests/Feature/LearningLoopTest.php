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

        $attemptsQuery = fn () => Attempt::where('tenant_id', $tenant->id)
            ->where('user_id', $learner->id)
            ->where('question_id', $question->id);

        $this->actingAsInTenant($learner, $tenant)->post($endpoint, $payload($wrongAnswer->id))->assertOk();
        $firstAttempt = $attemptsQuery()->sole();
        $this->assertFalse($firstAttempt->correct);

        $this->actingAsInTenant($learner, $tenant)->post($endpoint, $payload($correctAnswer->id))->assertOk();

        // Zwei parallele Requests können in Postgres denselben Transaktions-
        // Zeitstempel erhalten (created_at ist keine verlässliche Sortierung),
        // daher wird der zweite Versuch über die bereits bekannte erste
        // Attempt-ID abgegrenzt statt über eine chronologische Sortierung.
        $attempts = $attemptsQuery()->get();
        $secondAttempt = $attempts->first(fn ($attempt) => $attempt->id !== $firstAttempt->id);

        $this->assertCount(2, $attempts);
        $this->assertNotNull($secondAttempt);
        $this->assertTrue($secondAttempt->correct);

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

    /**
     * Regressionstest: Progress hat einen zusammengesetzten Primärschlüssel
     * ohne eigene id-Spalte. Eloquent-Collection::only() arbeitet intern über
     * den Modell-Primärschlüssel und lieferte dadurch für jedes Modul immer
     * 0 gefestigte Fragen zurück, obwohl echte Fortschrittsdaten vorlagen.
     */
    public function test_course_overview_reports_mastered_questions_per_module(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $endpoint = "/courses/{$course->id}/learn/attempts";
        $payload = ['revision_id' => $revision->id, 'answer_id' => $correctAnswer->id, 'mode' => 'smarttrainer'];

        // Drei richtige Antworten (insgesamt, s. MasteryCalculatorTest) => learning_state "gefestigt".
        for ($i = 0; $i < 3; $i++) {
            $this->actingAsInTenant($learner, $tenant)->post($endpoint, $payload)->assertOk();
        }

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('1 / ', false);
    }

    public function test_smarttrainer_can_be_filtered_to_a_single_module(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_SEE');
        $moduleQuestionIds = $this->onAdmin(fn () => $module->questions()->pluck('content_question.id'));

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/learn?mode=smarttrainer&module={$module->id}");

        $response->assertOk();

        $revisionId = $response->viewData('revision')->question_id;
        $this->assertTrue($moduleQuestionIds->contains($revisionId));
    }

    public function test_smarttrainer_can_be_filtered_to_a_single_topic(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_SEE');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $category = $revision->smartmodus_kategorie ?: $revision->topic;

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/learn?mode=topic&module={$module->id}&topic=".urlencode($category));

        $response->assertOk();
        $returned = $response->viewData('revision');
        $this->assertSame($category, $returned->smartmodus_kategorie ?: $returned->topic);
    }

    public function test_smart_learning_overview_lists_modules_grouped_by_smartmodus_kategorie_with_mastery_counts(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $endpoint = "/courses/{$course->id}/learn/attempts";
        $payload = ['revision_id' => $revision->id, 'answer_id' => $correctAnswer->id, 'mode' => 'smarttrainer'];

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsInTenant($learner, $tenant)->post($endpoint, $payload)->assertOk();
        }

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn/overview");

        $response->assertOk();
        $response->assertSee($module->name);
        $response->assertSee($revision->smartmodus_kategorie ?: $revision->topic);
        $response->assertSee('1/', false);
    }

    public function test_smart_learning_overview_requires_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn/overview");

        $response->assertForbidden();
    }
}
