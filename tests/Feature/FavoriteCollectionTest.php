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

    /**
     * Bug-Review N12: FavoriteCollectionController::store() prüfte bislang
     * nicht, ob der Lerner überhaupt ein aktives Entitlement für den
     * (per Route-Model-Binding beliebig wählbaren) Kurs hat -- anders als
     * der bereits geprüfte FavoriteController::store().
     */
    public function test_creating_a_collection_for_a_course_without_an_entitlement_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        // Bewusst kein grantEntitlement().

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/favorites/collections", [
            'name' => 'Unberechtigt',
        ]);

        $response->assertForbidden();
        $this->asTenant($tenant, function () use ($tenant, $learner, $course) {
            $this->assertDatabaseMissing('favorite_collection', ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id]);
        });
    }

    /**
     * Bug-Review N12: FavoriteCollectionController::sync() prüfte bislang
     * weder ein Entitlement für den Kurs noch, ob die Frage überhaupt zu
     * diesem Kurs gehört -- anders als der bereits geprüfte
     * FavoriteController::store().
     */
    public function test_syncing_a_question_for_a_course_without_an_entitlement_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);
        // Bewusst kein grantEntitlement().

        $response = $this->actingAsInTenant($learner, $tenant)->put("/courses/{$course->id}/favorites/{$question->id}/collections", [
            'context' => 'smart_learning',
            'collection_ids' => [],
        ]);

        $response->assertForbidden();
    }

    public function test_syncing_a_question_that_does_not_belong_to_the_course_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);
        $moduleB = $this->existingModule('UBI');
        [$questionFromB] = $this->anyPublishedQuestionIn($moduleB);

        $response = $this->actingAsInTenant($learner, $tenant)->put("/courses/{$courseA->id}/favorites/{$questionFromB->id}/collections", [
            'context' => 'smart_learning',
            'collection_ids' => [],
        ]);

        $response->assertForbidden();
    }

    /**
     * Bug-Review N3: FavoriteCollectionService::detachFromAllCollections()
     * löschte bislang die FavoriteCollectionItem-Zeilen nur nach tenant_id/
     * question_id/context -- ohne Einschränkung auf die eigenen
     * Collections des Lerners (anders als syncQuestion()). Wählt ein
     * Lerner einen eigenen Favoriten ab (FavoriteController::destroy),
     * verliert dieselbe Frage dadurch die Collection-Zuordnung bei JEDEM
     * anderen Lerner derselben Bootsschule mit.
     */
    public function test_removing_a_favorite_does_not_affect_another_learners_collection_assignment_for_the_same_question(): void
    {
        $tenant = $this->createTestTenant();
        $learnerA = $this->createTenantUser($tenant, 'learner');
        $learnerB = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learnerA, $course);
        $this->grantEntitlement($tenant, $learnerB, $course);
        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        $this->actingAsInTenant($learnerA, $tenant)->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);
        $this->actingAsInTenant($learnerB, $tenant)->post("/favorites/{$question->id}", ['context' => 'smart_learning', 'course_id' => $course->id]);

        // learnerA wählt die Frage wieder ab -- das darf nur SEINE eigene
        // Collection-Zuordnung entfernen, nicht die von learnerB.
        $this->actingAsInTenant($learnerA, $tenant)->delete("/favorites/{$question->id}", ['context' => 'smart_learning']);

        $this->onAdmin(function () use ($tenant, $learnerA, $learnerB, $question) {
            $this->assertFalse(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learnerA->id)->where('question_id', $question->id)->exists());
            $this->assertTrue(Favorite::where('tenant_id', $tenant->id)->where('user_id', $learnerB->id)->where('question_id', $question->id)->exists());

            $learnerBCollectionIds = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $learnerB->id)->pluck('id');
            $this->assertTrue(
                FavoriteCollectionItem::where('tenant_id', $tenant->id)
                    ->where('question_id', $question->id)
                    ->where('context', 'smart_learning')
                    ->whereIn('favorite_collection_id', $learnerBCollectionIds)
                    ->exists(),
                'learnerB hat seine Collection-Zuordnung für diese Frage verloren, obwohl nur learnerA seinen Favoriten abgewählt hat.'
            );
        });
    }

    /**
     * Bug-Review R4-3: der Collection-Name floss bisher direkt (nur HTML-
     * escaped) in den JS-String von @submit="confirm('...')" ein. Ein Name
     * mit einem rohen Apostroph brach aus diesem String-Literal aus, sobald
     * der Browser die von Blade erzeugte &#039;-Entity beim Parsen des
     * Attributwerts wieder in ein echtes ' zurückverwandelte -- Alpine sah
     * dann zusätzlichen, vom Angreifer bestimmten JS-Code (Self-XSS über
     * den eigenen Collection-Namen).
     */
    public function test_a_collection_name_with_a_quote_does_not_break_out_of_the_inline_confirm_script(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $maliciousName = "x'); alert(document.domain); //";

        $this->actingAsInTenant($learner, $tenant)
            ->post("/courses/{$course->id}/favorites/collections", ['name' => $maliciousName])
            ->assertSessionHasNoErrors();

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");
        $response->assertOk();

        $html = $response->getContent();

        // Die rohe, für ein JS-String-Literal gefährliche Eingabe darf an
        // KEINER Stelle unverändert im Markup auftauchen.
        $this->assertStringNotContainsString($maliciousName, $html);

        // Stattdessen muss der Name per json_encode(..., JSON_HEX_APOS) als
        // reiner Datenwert über x-data hereinkommen -- das Apostroph als
        // ' statt als rohes Zeichen (zusätzlich HTML-escaped durch
        // Blades {{ }}, da der JSON-String selbst in "..." steckt).
        $this->assertStringContainsString(
            'collectionName: '.e(json_encode($maliciousName, JSON_HEX_APOS | JSON_HEX_QUOT)),
            $html
        );
    }
}
