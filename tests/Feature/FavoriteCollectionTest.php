<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Favoriten-Collections (siehe FavoriteCollectionService): pro Kurs kann
 * ein Lerner seine Favoriten in frei benannte Gruppen einteilen, zusätzlich
 * zur stets vorhandenen, nicht löschbaren Default-Collection. Eine Frage
 * kann gleichzeitig in mehreren Collections liegen, auch eine bisher nur
 * als Fehler erkannte Frage lässt sich direkt einsortieren.
 */
class FavoriteCollectionTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_visiting_the_favorites_page_creates_a_default_collection(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning")->assertOk();

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $collection = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->first();
            $this->assertNotNull($collection);
            $this->assertTrue($collection->is_default);
            $this->assertSame('Favoriten', $collection->name);
        });
    }

    public function test_favoriting_a_question_with_course_id_adds_it_to_the_default_collection(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id])
            ->assertRedirect();

        $this->onAdmin(function () use ($tenant, $learner, $course, $question) {
            $default = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->where('is_default', true)->first();
            $this->assertNotNull($default);
            $this->assertTrue($default->items()->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
        });
    }

    public function test_learner_can_create_rename_and_delete_a_collection(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)
            ->post("/courses/{$course->id}/favorites/collections", ['name' => 'Vor der Prüfung'])
            ->assertRedirect();

        $collection = $this->onAdmin(fn () => FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('name', 'Vor der Prüfung')->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)
            ->patch("/favorites/collections/{$collection->id}", ['name' => 'Letzte Woche'])
            ->assertRedirect();

        $this->onAdmin(function () use ($collection) {
            $this->assertSame('Letzte Woche', $collection->fresh()->name);
        });

        $this->actingAsInTenant($learner, $tenant)
            ->delete("/favorites/collections/{$collection->id}")
            ->assertRedirect();

        $this->onAdmin(function () use ($collection) {
            $this->assertNull(FavoriteCollection::find($collection->id));
        });
    }

    public function test_default_collection_cannot_be_deleted(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");
        $default = $this->onAdmin(fn () => FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->where('is_default', true)->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)
            ->delete("/favorites/collections/{$default->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('collection');

        $this->onAdmin(function () use ($default) {
            $this->assertNotNull(FavoriteCollection::find($default->id));
        });
    }

    public function test_a_question_can_be_assigned_to_multiple_collections_at_once(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/favorites/collections", ['name' => 'Schwierig']);
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/favorites/collections", ['name' => 'Vor der Prüfung']);

        [$schwierig, $vorDerPruefung] = $this->onAdmin(fn () => [
            FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('name', 'Schwierig')->firstOrFail(),
            FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('name', 'Vor der Prüfung')->firstOrFail(),
        ]);

        $this->actingAsInTenant($learner, $tenant)
            ->put("/courses/{$course->id}/favorites/{$question->id}/collections", [
                'context' => 'smart_learning',
                'collection_ids' => [$schwierig->id, $vorDerPruefung->id],
            ])
            ->assertRedirect();

        $this->onAdmin(function () use ($tenant, $learner, $question, $schwierig, $vorDerPruefung) {
            $this->assertTrue(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
            $this->assertTrue($schwierig->items()->where('question_id', $question->id)->exists());
            $this->assertTrue($vorDerPruefung->items()->where('question_id', $question->id)->exists());
        });
    }

    public function test_removing_a_question_from_all_collections_unfavorites_it(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);

        $this->actingAsInTenant($learner, $tenant)
            ->put("/courses/{$course->id}/favorites/{$question->id}/collections", [
                'context' => 'smart_learning',
                'collection_ids' => [],
            ])
            ->assertRedirect();

        $this->onAdmin(function () use ($tenant, $learner, $question) {
            $this->assertFalse(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
        });
    }

    public function test_unfavoriting_a_question_removes_it_from_all_collections(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);

        $this->actingAsInTenant($learner, $tenant)
            ->delete("/favorites/{$question->id}", ['context' => 'smart_learning'])
            ->assertRedirect();

        $this->onAdmin(function () use ($tenant, $question) {
            $this->assertSame(0, FavoriteCollectionItem::where('tenant_id', $tenant->id)->where('question_id', $question->id)->count());
        });
    }

    public function test_a_wrong_answer_question_can_be_sorted_into_a_collection_directly(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        // Die Frage wurde noch nie favorisiert -- nur als Fehler erkannt
        // (hier direkt über die Collection-Zuordnung simuliert, ohne vorher
        // über /favorites zu favorisieren).
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");
        $default = $this->onAdmin(fn () => FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->where('is_default', true)->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)
            ->put("/courses/{$course->id}/favorites/{$question->id}/collections", [
                'context' => 'smart_learning',
                'collection_ids' => [$default->id],
            ])
            ->assertRedirect();

        $this->onAdmin(function () use ($tenant, $learner, $question) {
            $this->assertTrue(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->where('context', 'smart_learning')->exists());
        });
    }

    public function test_learner_cannot_manage_another_learners_collection(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $intruder = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $owner, $course);
        $this->grantEntitlement($tenant, $intruder, $course);

        $this->actingAsInTenant($owner, $tenant)->post("/courses/{$course->id}/favorites/collections", ['name' => 'Privat']);
        $collection = $this->onAdmin(fn () => FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $owner->id)->where('name', 'Privat')->firstOrFail());

        $this->actingAsInTenant($intruder, $tenant)
            ->patch("/favorites/collections/{$collection->id}", ['name' => 'Übernommen'])
            ->assertNotFound();

        $this->actingAsInTenant($intruder, $tenant)
            ->delete("/favorites/collections/{$collection->id}")
            ->assertNotFound();
    }
}
