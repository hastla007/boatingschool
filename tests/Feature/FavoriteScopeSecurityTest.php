<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N8: FavoriteController::store ließ sich bislang für
 * beliebige globale Fragen aufrufen, ohne Prüfung auf Kurs oder
 * Entitlement.
 */
class FavoriteScopeSecurityTest extends TestCase
{
    use InteractsWithTenants;

    public function test_favoriting_a_question_without_a_course_id_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning']);

        $response->assertSessionHasErrors('course_id');
    }

    public function test_favoriting_a_question_without_an_entitlement_for_the_course_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        // Bewusst kein grantEntitlement().
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('favorite', ['question_id' => $question->id, 'user_id' => $learner->id]);
    }

    public function test_favoriting_a_question_that_does_not_belong_to_the_given_course_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);
        $moduleB = $this->existingModule('UBI');
        [$questionFromB] = $this->anyPublishedQuestionIn($moduleB);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$questionFromB->id}", ['context' => 'smart_learning', 'course_id' => $courseA->id]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('favorite', ['question_id' => $questionFromB->id, 'user_id' => $learner->id]);
    }
}
