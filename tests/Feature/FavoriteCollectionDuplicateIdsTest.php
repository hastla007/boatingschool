<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\FavoriteCollection;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Doppelte Sammlungs-IDs führen zu einer Fehlerseite):
 * FavoriteCollectionController::sync() validiert collection_ids.* nur auf
 * "uuid", nicht auf Eindeutigkeit -- ein Lerner kann (manipuliertes
 * Formular, doppelter Checkbox-Wert) dieselbe Collection-ID mehrfach
 * übermitteln. FavoriteCollectionService::syncQuestion() reichte das über
 * array_intersect() unverändert durch: die zweite Zeile mit derselben
 * favorite_collection_id verletzte den aus (favorite_collection_id,
 * question_id, context) bestehenden Primärschlüssel von
 * favorite_collection_item und führte zu einer unbehandelten
 * Datenbank-Exception (500) statt einfach einmal einsortiert zu werden.
 */
class FavoriteCollectionDuplicateIdsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_syncing_with_a_duplicate_collection_id_does_not_error_and_sorts_the_question_in_once(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/favorites/collections", ['name' => 'Schwierig']);
        $schwierig = $this->onAdmin(fn () => FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('name', 'Schwierig')->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)
            ->put("/courses/{$course->id}/favorites/{$question->id}/collections", [
                'context' => 'smart_learning',
                'collection_ids' => [$schwierig->id, $schwierig->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $this->onAdmin(function () use ($tenant, $learner, $question, $schwierig) {
            $this->assertTrue(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
                ->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
            $this->assertSame(1, $schwierig->items()->where('question_id', $question->id)->count());
        });
    }
}
