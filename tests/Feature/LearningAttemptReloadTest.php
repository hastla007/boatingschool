<?php

namespace Tests\Feature;

use App\Models\Attempt;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B20: LearningController::storeAttempt() rendete die
 * beantwortete Frage bisher direkt als POST-Antwort -- ein Neuladen (F5)
 * veranlasste den Browser dadurch zur erneuten Formular-Einreichung
 * (derselbe POST) und legte einen zweiten Attempt für dieselbe Frage an.
 * storeAttempt() leitet jetzt auf eine GET-Route weiter (POST/Redirect/
 * GET); ein Neuladen ruft nur noch diese GET-Route erneut auf.
 */
class LearningAttemptReloadTest extends TestCase
{
    use InteractsWithTenants;

    public function test_submitting_an_answer_redirects_instead_of_rendering_the_result_directly(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Attempt::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('question_id', $question->id)->count());

        $resultPage = $this->actingAsInTenant($learner, $tenant)->get($response->headers->get('Location'));

        $resultPage->assertOk();
        $resultPage->assertSee('Richtig!');
    }

    public function test_reloading_the_result_page_does_not_create_a_second_attempt(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
        ]);
        $resultUrl = $response->headers->get('Location');

        // Simuliert ein Neuladen (F5) der Ergebnisseite -- früher hätte das
        // (bei einer erneuten Formular-Einreichung) einen zweiten Attempt
        // angelegt; jetzt ist es ein harmloses GET auf dieselbe Resource.
        $this->actingAsInTenant($learner, $tenant)->get($resultUrl)->assertOk();
        $this->actingAsInTenant($learner, $tenant)->get($resultUrl)->assertOk();
        $this->actingAsInTenant($learner, $tenant)->get($resultUrl)->assertOk();

        $this->assertSame(1, Attempt::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('question_id', $question->id)->count());
    }

    public function test_a_learner_cannot_view_another_learners_attempt_result(): void
    {
        $tenant = $this->createTestTenant();
        $learnerA = $this->createTenantUser($tenant, 'learner');
        $learnerB = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learnerA, $course);
        $this->grantEntitlement($tenant, $learnerB, $course);

        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $response = $this->actingAsInTenant($learnerA, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
        ]);
        $resultUrl = $response->headers->get('Location');

        $this->actingAsInTenant($learnerB, $tenant)->get($resultUrl)->assertForbidden();
    }
}
