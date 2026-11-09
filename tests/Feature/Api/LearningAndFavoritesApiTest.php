<?php

namespace Tests\Feature\Api;

use App\Models\ContentQuestionRevision;
use App\Models\FavoriteCollection;
use App\Services\MasteryCalculator;
use Illuminate\Support\Str;

/** Mobile-API: Kurse, Smarttrainer (Lernlogik wie im Web), Idempotenz, Favoriten. */
class LearningAndFavoritesApiTest extends MobileApiTestCase
{
    public function test_courses_list_shows_entitled_course_with_access_and_capabilities(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $list = $this->api('GET', '/courses', $token, $tenant)->assertOk();
        $entry = collect($list->json('data'))->firstWhere('id', $course->id);
        $this->assertSame('active', $entry['access']['status']);
        $this->assertContains('smarttrainer', $entry['capabilities']);
        $this->assertContains('exam', $entry['capabilities']);
        $this->assertSame('not_granted', collect($list->json('data'))->firstWhere('code', 'FKN')['access']['status'] ?? 'not_granted');

        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertOk()->assertJsonStructure(['data' => ['course', 'progress', 'modules']]);
        $this->api('GET', "/courses/{$course->id}/progress", $token, $tenant)->assertOk()->assertJsonStructure(['data' => ['overall', 'modules']]);

        // Ohne Zugang: eindeutiger Fehlercode.
        $fkn = $this->existingCourse('FKN');
        $this->api('GET', "/courses/{$fkn->id}/overview", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_trainer_question_has_no_solution_and_answering_follows_the_web_learning_logic(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $next = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk();
        $question = $next->json('data.question');
        $this->assertArrayNotHasKey('is_correct', $question['answers'][0] ?? []);
        $this->assertStringNotContainsString('correct_answer', $next->getContent());
        $this->assertSame(['current' => 0, 'required' => 3], $next->json('data.streak'));

        $revision = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->findOrFail($question['revision_id']));
        $correct = $revision->answers->firstWhere('is_correct', true);
        $wrong = $revision->answers->firstWhere('is_correct', false);
        $body = ['question_id' => $question['id'], 'revision_id' => $question['revision_id'], 'selected_answer_ids' => [$correct->id]];

        $key = (string) Str::uuid();
        $first = $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.correct', true)->assertJsonPath('data.streak.current', 1)
            ->assertJsonPath('data.completed', false);
        $this->assertNotEmpty($first->json('data.correct_answer_ids'));

        // Wiederholung mit demselben Schlüssel: dieselbe Antwort, kein zweiter Versuch.
        $replay = $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body, ['Idempotency-Key' => $key])->assertCreated();
        $this->assertSame($first->json('data.attempt_id'), $replay->json('data.attempt_id'));
        // Derselbe Schlüssel mit anderem Inhalt: Konflikt.
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, ['selected_answer_ids' => [$wrong->id]] + $body, ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        // Ohne Schlüssel: abgelehnt.
        $this->json('POST', "/api/v1/courses/{$course->id}/attempts", $body, ['Authorization' => 'Bearer '.$token, 'X-Tenant-Slug' => $tenant->slug])
            ->assertStatus(422)->assertJsonValidationErrors('Idempotency-Key');

        // Zweimal richtig, dann falsch: Serie zurück auf 0 (wie im Web).
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body)->assertJsonPath('data.streak.current', 2);
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, ['selected_answer_ids' => [$wrong->id]] + $body)
            ->assertJsonPath('data.correct', false)->assertJsonPath('data.streak.current', 0);
        foreach ([1, 2, 2] as $streak) {
            // Dritte richtige Antwort direkt hintereinander nach einem Fehler festigt noch nicht (Abstandsregel wie im Web).
            $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body)->assertJsonPath('data.streak.current', $streak);
        }
        $this->travel(MasteryCalculator::SPACING_MINUTES + 1)->minutes();
        $this->api('POST', "/courses/{$course->id}/attempts", $user, $tenant, $body)
            ->assertJsonPath('data.streak.current', 3)->assertJsonPath('data.completed', true)->assertJsonPath('data.learning_state', 'gefestigt');

        // Einfachauswahl mit zwei Antworten: abgelehnt.
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, ['selected_answer_ids' => [$correct->id, $wrong->id]] + $body)
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_attempts_of_other_users_are_not_visible(): void
    {
        $tenant = $this->createTestTenant();
        $a = $this->createTenantUser($tenant, 'learner');
        $b = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $a, $course);
        $this->grantEntitlement($tenant, $b, $course);
        $tokenA = $this->apiLogin($a);

        $question = $this->api('GET', "/courses/{$course->id}/trainer/next", $tokenA, $tenant)->json('data.question');
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->findOrFail($question['revision_id']));
        $attemptId = $this->api('POST', "/courses/{$course->id}/attempts", $tokenA, $tenant, [
            'question_id' => $question['id'], 'revision_id' => $question['revision_id'], 'selected_answer_ids' => [$revision->answers->first()->id],
        ])->assertCreated()->json('data.attempt_id');

        $this->api('GET', "/courses/{$course->id}/attempts/{$attemptId}", $tokenA, $tenant)->assertOk();
        $this->api('GET', "/courses/{$course->id}/attempts/{$attemptId}", $b, $tenant)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_favorites_and_collections(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $questionId = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->json('data.question.id');

        $collections = $this->api('GET', "/courses/{$course->id}/favorite-collections", $token, $tenant)->assertOk()->assertJsonCount(1, 'data');
        $defaultId = $collections->json('data.0.id');
        $this->assertTrue($collections->json('data.0.is_default'));

        $created = $this->api('POST', "/courses/{$course->id}/favorite-collections", $token, $tenant, ['name' => 'Funkverkehr'])->assertCreated();
        $ownId = $created->json('data.id');
        $this->api('POST', "/courses/{$course->id}/favorite-collections", $token, $tenant, ['name' => 'Funkverkehr'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        // Ohne Angabe landet der Favorit in der Standard-Sammlung; mit Angabe in genau diesen.
        $this->api('PUT', "/courses/{$course->id}/favorites/{$questionId}", $token, $tenant)->assertOk()->assertJsonPath('data.collection_ids', [$defaultId]);
        $this->api('PUT', "/courses/{$course->id}/favorites/{$questionId}", $token, $tenant, ['collection_ids' => [$defaultId, $ownId]])
            ->assertOk()->assertJsonCount(2, 'data.collection_ids');
        $this->api('GET', "/courses/{$course->id}/favorites?collection_id={$ownId}", $token, $tenant)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', "/courses/{$course->id}/trainer/next?mode=favorites", $token, $tenant)->assertOk()->assertJsonPath('data.question.id', $questionId);

        $this->api('PATCH', "/courses/{$course->id}/favorite-collections/{$ownId}", $token, $tenant, ['name' => 'Notverkehr'])->assertOk()->assertJsonPath('data.name', 'Notverkehr');
        $this->api('DELETE', "/courses/{$course->id}/favorite-collections/{$defaultId}", $token, $tenant)->assertStatus(409)->assertJsonPath('code', 'COLLECTION_NOT_DELETABLE');
        $this->api('DELETE', "/courses/{$course->id}/favorite-collections/{$ownId}", $token, $tenant)->assertNoContent();
        $this->api('DELETE', "/courses/{$course->id}/favorites/{$questionId}", $token, $tenant)->assertNoContent();
        $this->api('GET', "/courses/{$course->id}/favorites", $token, $tenant)->assertOk()->assertJsonCount(0, 'data');

        // Fremde Sammlung: nicht auffindbar.
        $other = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $other, $course);
        $foreignId = $this->onAdmin(fn () => FavoriteCollection::create(['tenant_id' => $tenant->id, 'user_id' => $other->id, 'course_id' => $course->id, 'name' => 'Fremd', 'is_default' => false, 'sort_order' => 1])->id);
        $this->api('PATCH', "/courses/{$course->id}/favorite-collections/{$foreignId}", $token, $tenant, ['name' => 'X'])->assertNotFound();
    }
}
